import sys
import json
import math
import re
import unicodedata
import csv
import os
from datetime import datetime, timedelta
from difflib import SequenceMatcher

# -----------------------
# Tunable thresholds
# -----------------------
FUZZY_NAME_THRESHOLD = 0.87
PHONETIC_NAME_THRESHOLD = 0.75
REQUIRE_SAME_DOB_FOR_FUZZY = True
CANDIDATE_GROUP_BY = ("province", "city")

# -----------------------
# Utilities
# -----------------------

def sanitize(obj):
    if isinstance(obj, dict):
        return {k: sanitize(v) for k, v in obj.items()}
    elif isinstance(obj, list):
        return [sanitize(v) for v in obj]
    elif isinstance(obj, float) and (math.isnan(obj) or math.isinf(obj)):
        return None
    return obj

def is_blank(x):
    if x is None:
        return True
    if isinstance(x, str):
        s = x.strip().lower()
        return s == "" or s in {"-", "n/a", "na", "none", "null"}
    return False

def strip_accents(s: str) -> str:
    if not isinstance(s, str):
        return ""
    nfkd = unicodedata.normalize("NFKD", s)
    return "".join(c for c in nfkd if not unicodedata.combining(c))

def normalize_spaces(s: str) -> str:
    return re.sub(r"\s+", " ", s).strip()

def norm_text(s: str) -> str:
    s = strip_accents(s or "")
    s = s.replace(".", " ").replace(",", " ").replace("-", " ")
    s = normalize_spaces(s.lower())
    return s

def soundex(word: str) -> str:
    if not word:
        return ""
    word = norm_text(word)
    if not word:
        return ""
    first_letter = word[0].upper()
    mapping = {
        **dict.fromkeys(list("bfpv"), "1"),
        **dict.fromkeys(list("cgjkqsxz"), "2"),
        **dict.fromkeys(list("dt"), "3"),
        "l": "4",
        **dict.fromkeys(list("mn"), "5"),
        "r": "6",
    }
    tail, prev = [], ""
    for ch in word[1:]:
        if ch in "hw":
            code = ""
        elif ch in mapping:
            code = mapping[ch]
        else:
            code = ""
        if code != prev:
            tail.append(code)
        if code != "":
            prev = code
    code = first_letter + "".join([c for c in tail if c]) + "000"
    return code[:4]

def normalize_date(val):
    if val is None:
        return ""
    if isinstance(val, (int, float)) and not isinstance(val, bool):
        try:
            base = datetime(1899, 12, 30)
            dt = base + timedelta(days=int(val))
            return dt.strftime("%Y-%m-%d")
        except Exception:
            return ""
    s = str(val).strip()
    if s == "":
        return ""
    fmts = [
        "%Y-%m-%d", "%d/%m/%Y", "%m/%d/%Y", "%d-%m-%Y", "%Y/%m/%d",
        "%d-%b-%Y", "%d-%b-%y", "%d-%m-%y"
    ]
    for f in fmts:
        try:
            return datetime.strptime(s, f).strftime("%Y-%m-%d")
        except Exception:
            continue
    try:
        return datetime.fromisoformat(s).strftime("%Y-%m-%d")
    except Exception:
        pass
    return ""

def make_name_key(first, middle, last, ext):
    f = norm_text(first)
    m = norm_text(middle)
    l = norm_text(last)
    e = norm_text(ext)
    m_initial = (m[0] if m else "")
    return normalize_spaces(" ".join([f, m_initial, l, e])).strip()

def name_similarity(a: str, b: str) -> float:
    return SequenceMatcher(None, a, b).ratio()

def all_pairs(indices):
    n = len(indices)
    for i in range(n):
        for j in range(i + 1, n):
            yield indices[i], indices[j]

# -----------------------
# Core analysis
# -----------------------

def analyze(rows):
    prepared = []
    for idx, r in enumerate(rows):
        name_key = make_name_key(r.get("first_name"), r.get("middle_name"),
                                 r.get("last_name"), r.get("ext_name"))
        prepared.append({
            "idx": idx,
            "raw": r,
            "source_file": r.get("source_file"),
            "name_key": name_key,
            "first_sdx": soundex(r.get("first_name") or ""),
            "last_sdx": soundex(r.get("last_name") or ""),
            "birth_date": normalize_date(r.get("birth_date")),
            "region": norm_text(r.get("region")),
            "province": norm_text(r.get("province")),
            "city": norm_text(r.get("city")),
            "barangay": norm_text(r.get("barangay")),
            "reasons": set(),
            "dup_group": None
        })

    # --- Missing data
    required_fields = ["first_name", "last_name", "birth_date", "region", "province", "city", "barangay"]
    for p in prepared:
        if any(is_blank(p["raw"].get(f)) for f in required_fields):
            p["reasons"].add("Missing Data")

    # --- Duplicate grouping helper
    dup_counter = 1
    def assign_group(i, j):
        nonlocal dup_counter
        gi, gj = prepared[i]["dup_group"], prepared[j]["dup_group"]
        if gi and gj:
            if gi != gj:
                for p in prepared:
                    if p["dup_group"] == gj:
                        p["dup_group"] = gi
        elif gi or gj:
            g = gi or gj
            prepared[i]["dup_group"] = g
            prepared[j]["dup_group"] = g
        else:
            g = f"DUP{dup_counter}"
            dup_counter += 1
            prepared[i]["dup_group"] = g
            prepared[j]["dup_group"] = g

    # --- Exact duplicates
    buckets = {}
    for p in prepared:
        key = (p["name_key"], p["birth_date"], p["region"], p["province"], p["city"], p["barangay"])
        buckets.setdefault(key, []).append(p["idx"])
    for idxs in buckets.values():
        if len(idxs) > 1:
            for i, j in all_pairs(sorted(idxs)):
                prepared[i]["reasons"].add("Exact Duplicate")
                prepared[j]["reasons"].add("Exact Duplicate")
                assign_group(i, j)

    # --- Fuzzy + phonetic
    grp = {}
    for p in prepared:
        gkey = tuple(p.get(k, "") for k in CANDIDATE_GROUP_BY)
        grp.setdefault(gkey, []).append(p)

    for items in grp.values():
        idxs = [it["idx"] for it in items]
        for i_idx, j_idx in all_pairs(idxs):
            i, j = prepared[i_idx], prepared[j_idx]
            sim = name_similarity(i["name_key"], j["name_key"])
            same_dob = (i["birth_date"] != "" and i["birth_date"] == j["birth_date"])
            phonetic_match = (i["first_sdx"] == j["first_sdx"] and i["last_sdx"] == j["last_sdx"])

            if sim >= FUZZY_NAME_THRESHOLD and same_dob:
                i["reasons"].add("Possible Duplicate")
                j["reasons"].add("Possible Duplicate")
                assign_group(i_idx, j_idx)
            elif phonetic_match and sim >= PHONETIC_NAME_THRESHOLD:
                i["reasons"].add("Sounds-Like Duplicate")
                j["reasons"].add("Sounds-Like Duplicate")
                assign_group(i_idx, j_idx)

    # --- Final flat table
    table = []
    for p in prepared:
        raw = p["raw"]
        table.append({
            "Dup Group": p["dup_group"] or "",
            "Beneficiary ID": raw.get("beneficiary_id"),
            "List ID": raw.get("list_id"),
            "Source File": os.path.basename(p["source_file"]),  # ✅ filename only
            "Full Name": " ".join([str(raw.get("first_name") or ""), str(raw.get("middle_name") or ""), str(raw.get("last_name") or ""), str(raw.get("ext_name") or "")]).strip(),
            "Birth Date": p["birth_date"],
            "Region": raw.get("region"),
            "Province": raw.get("province"),
            "City": raw.get("city"),
            "Barangay": raw.get("barangay"),
            "Marital Status": raw.get("marital_status"),
            "Reason": ", ".join(sorted(p["reasons"])) if p["reasons"] else ""
        })

    # ✅ Sort grouped duplicates together
    table.sort(key=lambda r: (r["Dup Group"] or "ZZZ", r["Full Name"]))
    return table

# -----------------------
# File loader + Entrypoint
# -----------------------

def load_file(filename):
    with open(filename, newline='', encoding="utf-8") as f:
        reader = csv.DictReader(f)
        rows = list(reader)
        for r in rows:
            r["source_file"] = os.path.basename(filename)  # ✅ filename only
            mapping = {
                "First Name": "first_name",
                "Middle Name": "middle_name",
                "Last Name": "last_name",
                "Ext": "ext_name",
                "Birth Date": "birth_date",
                "Region": "region",
                "Province": "province",
                "City": "city",
                "Barangay": "barangay",
                "Marital Status": "marital_status",
                "List ID": "list_id",
                "Beneficiary ID": "beneficiary_id"
            }
            for old, new in mapping.items():
                if old in r:
                    r[new] = r.pop(old)
        return rows

def main():
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("files", nargs="+", help="Input files (CSV or JSON)")
    args = parser.parse_args()

    rows = []
    for fname in args.files:
        rows.extend(load_file(fname))

    try:
        out = analyze(rows)
        print(json.dumps(out, ensure_ascii=False, indent=2))
    except Exception as e:
        print(json.dumps({"error": f"Analyzer failed: {e}"}))
        sys.exit(1)

if __name__ == "__main__":
    main()
