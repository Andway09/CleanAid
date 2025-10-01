import sys
import json
import math
import re
import unicodedata
from datetime import datetime, timedelta
from difflib import SequenceMatcher

# -----------------------
# Tunable thresholds (tightened for accuracy)
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
    fmts = ["%Y-%m-%d", "%d/%m/%Y", "%m/%d/%Y", "%d-%m-%Y", "%Y/%m/%d"]
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
            "name_key": name_key,
            "first_sdx": soundex(r.get("first_name") or ""),
            "last_sdx": soundex(r.get("last_name") or ""),
            "birth_date": normalize_date(r.get("birth_date")),
            "region": norm_text(r.get("region")),
            "province": norm_text(r.get("province")),
            "city": norm_text(r.get("city")),
            "barangay": norm_text(r.get("barangay"))
        })

    # Required fields
    required_fields = ["first_name", "last_name", "birth_date", "region", "province", "city", "barangay"]

    missing_rows = []
    missing_set = set()
    for p in prepared:
        if any(is_blank(p["raw"].get(f)) for f in required_fields):
            missing_rows.append(p["raw"])
            missing_set.add(p["idx"])

    exact_pairs, fuzzy_pairs, phonetic_pairs = [], [], []
    used_pairs = set()

    # --- Exact duplicates
    buckets = {}
    for p in prepared:
        key = (p["name_key"], p["birth_date"], p["region"], p["province"], p["city"], p["barangay"])
        buckets.setdefault(key, []).append(p["idx"])
    for idxs in buckets.values():
        if len(idxs) > 1:
            for i, j in all_pairs(sorted(idxs)):
                exact_pairs.append({"row1_index": i, "row2_index": j})
                used_pairs.add((i, j))

    # --- Fuzzy + phonetic
    grp = {}
    for p in prepared:
        gkey = tuple(p.get(k, "") for k in CANDIDATE_GROUP_BY)
        grp.setdefault(gkey, []).append(p)

    for items in grp.values():
        idxs = [it["idx"] for it in items]
        for i_idx, j_idx in all_pairs(idxs):
            if (i_idx, j_idx) in used_pairs:
                continue
            i, j = prepared[i_idx], prepared[j_idx]
            sim = name_similarity(i["name_key"], j["name_key"])
            same_dob = (i["birth_date"] != "" and i["birth_date"] == j["birth_date"])
            phonetic_match = (i["first_sdx"] == j["first_sdx"] and i["last_sdx"] == j["last_sdx"])

            if sim >= FUZZY_NAME_THRESHOLD and same_dob:
                fuzzy_pairs.append({
                    "row1_index": i_idx,
                    "row2_index": j_idx,
                    "similarity": int(round(sim * 100))
                })
                used_pairs.add((i_idx, j_idx))
                continue
            if phonetic_match and sim >= PHONETIC_NAME_THRESHOLD:
                phonetic_pairs.append({
                    "row1_index": i_idx,
                    "row2_index": j_idx,
                    "phonetic_code": f"{i['first_sdx']}-{i['last_sdx']}"
                })
                used_pairs.add((i_idx, j_idx))

    return sanitize({
        "summary": {
            "total_records": len(rows),
            "missing_count": len(missing_rows),
            "exact_duplicates_count": len(exact_pairs),
            "fuzzy_duplicates_count": len(fuzzy_pairs),
            "sounds_like_count": len(phonetic_pairs),
        },
        # Row can appear in both duplicates + missing
        "missing_data": missing_rows,
        "exact_duplicates": exact_pairs,
        "fuzzy_duplicates": fuzzy_pairs,
        "sounds_like_duplicates": phonetic_pairs
    })

# -----------------------
# Entrypoint
# -----------------------

def main():
    try:
        raw = sys.stdin.read()
        rows = json.loads(raw) if raw.strip() else []
        if not isinstance(rows, list):
            raise ValueError("Top-level JSON must be an array of row objects.")
    except Exception as e:
        print(json.dumps({"error": f"Invalid JSON input: {e}"}))
        sys.exit(1)
    try:
        out = analyze(rows)
        print(json.dumps(out, ensure_ascii=False))
    except Exception as e:
        print(json.dumps({"error": f"Analyzer failed: {e}"}))
        sys.exit(1)

if __name__ == "__main__":
    main()
