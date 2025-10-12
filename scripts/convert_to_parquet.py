#!/usr/bin/env python3
"""
convert_to_parquet.py
Converts one or more CSV or Excel files into a single Parquet file.
Usage:
    python convert_to_parquet.py <file1.csv> <file2.xlsx> ...
"""

import sys
import os
import pandas as pd

def main():
    # 🔧 Force UTF-8 encoding for Windows consoles
    sys.stdout.reconfigure(encoding='utf-8')
    sys.stderr.reconfigure(encoding='utf-8')

    if len(sys.argv) < 2:
        print("Error: No input files provided", flush=True)
        sys.exit(1)

    input_files = sys.argv[1:]
    dfs = []
    print("Reading files:", flush=True)

    for path in input_files:
        if not os.path.exists(path):
            print(f"❌ File not found: {path}", flush=True)
            sys.exit(1)
        ext = os.path.splitext(path)[1].lower()
        try:
            print(f"  - {path}", flush=True)
            if ext == ".csv":
                df = pd.read_csv(path, encoding='utf-8', low_memory=False)
            elif ext in [".xls", ".xlsx"]:
                df = pd.read_excel(path)
            else:
                print(f"❌ Unsupported file type: {path}", flush=True)
                sys.exit(1)
            dfs.append(df)
        except Exception as e:
            print(f"❌ Error reading {path}: {e}", flush=True)
            sys.exit(1)

    if not dfs:
        print("Error: No valid dataframes created.", flush=True)
        sys.exit(1)

    combined = pd.concat(dfs, ignore_index=True)
    base_dir = os.path.dirname(input_files[0])
    parquet_path = os.path.join(base_dir, "combined_output.parquet")

    try:
        combined.to_parquet(parquet_path, index=False)
        print(f"✅ Combined {len(input_files)} file(s) → {parquet_path}", flush=True)
        sys.exit(0)
    except Exception as e:
        print(f"❌ Error writing parquet file: {e}", flush=True)
        sys.exit(1)

if __name__ == "__main__":
    main()
