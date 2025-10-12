#!/usr/bin/env python3
"""
convert_to_parquet.py
Converts CSV or Excel file into Parquet format using pandas.
"""
import sys
import os
import pandas as pd

def main():
    if len(sys.argv) < 2:
        print("Error: No input file provided")
        sys.exit(1)

    input_path = sys.argv[1]
    if not os.path.exists(input_path):
        print(f"Error: File not found: {input_path}")
        sys.exit(1)

    base, _ = os.path.splitext(input_path)
    parquet_path = base + ".parquet"

    try:
        ext = os.path.splitext(input_path)[1].lower()
        if ext == ".csv":
            df = pd.read_csv(input_path)
        elif ext in [".xls", ".xlsx"]:
            df = pd.read_excel(input_path)
        else:
            print("Error: Unsupported file type")
            sys.exit(1)

        df.to_parquet(parquet_path, index=False)
        print(f"Converted to {parquet_path}")
    except Exception as e:
        print(f"Error: {e}")
        sys.exit(1)

if __name__ == "__main__":
    main()
