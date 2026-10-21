import pandas as pd
import os
import re
from openpyxl import load_workbook
from openpyxl.utils import get_column_letter
from collections import defaultdict
import warnings

warnings.filterwarnings("ignore")


# Load districts from CSV
def load_districts(csv_path="districts.csv"):
    """Load districts from CSV file"""
    districts_df = pd.read_csv(csv_path)
    # Create a list of all district names for matching
    district_names = districts_df["name"].str.upper().tolist()
    return districts_df, district_names


# Create district mapping for phone numbers
def create_phone_district_mapping():
    """
    Create a mapping of phone prefixes to districts
    You need to populate this based on your data
    """
    phone_mapping = {
        # Add actual mappings based on your data
        # Example: '075': 'KAMPALA',
    }
    return phone_mapping


def extract_district_from_text(text, district_names):
    """
    Extract district name from text by checking against district names
    """
    if pd.isna(text) or text == "":
        return None

    text_upper = str(text).upper()

    # Check for exact district name matches
    for district in district_names:
        # Check if district name appears as a whole word or as part of text
        if district in text_upper:
            return district

        # Check for district name with common variations
        district_variations = [
            district,
            district.replace(" CITY", ""),
            district.replace(" ", ""),
            district.replace(" ", "-"),
        ]

        for variation in district_variations:
            if variation in text_upper:
                return district

    return None


def get_district_for_payment(row, district_names, phone_mapping, payment_history=None):
    """
    Determine district for a payment based on narration, phone number, or other fields
    """
    # First priority: Check narration/text fields for district names
    text_fields = [
        "narration",
        "description",
        "remarks",
        "transaction_details",
        "notes",
        "comment",
        "details",
    ]

    for field in text_fields:
        if field in row and pd.notna(row[field]):
            field_value = str(row[field])
            district = extract_district_from_text(field_value, district_names)
            if district:
                return district

    # Second priority: Check all other fields for district names
    for col in row.index:
        if col.lower() not in ["phone", "phone_number", "mobile", "contact", "district"]:
            if pd.notna(row[col]):
                field_value = str(row[col])
                district = extract_district_from_text(field_value, district_names)
                if district:
                    return district

    # Third priority: Check phone number mapping if available
    if "phone" in row and pd.notna(row["phone"]):
        phone_str = str(row["phone"]).strip()
        # Check if phone number exists in phone mapping
        for prefix, district in phone_mapping.items():
            if phone_str.startswith(prefix):
                return district

        # Check if phone appears in any district's payment history
        if payment_history:
            for district, phones in payment_history.items():
                if phone_str in phones:
                    return district

    # Fourth priority: Check if district is already in data
    if "district" in row and pd.notna(row["district"]):
        district_name = str(row["district"]).strip().upper()
        if district_name in district_names:
            return district_name

    # If no district found, return as uncategorized
    return "UNCATEGORIZED"


def build_payment_history(df, district_names):
    """
    Build a history of which phone numbers have received payments in which districts
    """
    phone_district_history = defaultdict(set)

    # If phone column exists, map phones to districts
    if "phone" in df.columns:
        # First, try to categorize each row by narration
        temp_districts = []
        for _, row in df.iterrows():
            district = get_district_for_payment(row, district_names, {})
            temp_districts.append(district)

        df_temp = df.copy()
        df_temp["temp_district"] = temp_districts

        # Build phone history
        for _, row in df_temp.iterrows():
            if pd.notna(row["phone"]):
                phone = str(row["phone"]).strip()
                district = row["temp_district"]
                if district and district != "UNCATEGORIZED":
                    phone_district_history[district].add(phone)

    return phone_district_history


def process_payment_file(excel_file, districts_csv="districts.csv"):
    """
    Process a single payment file
    """
    # Load districts
    districts_df, district_names = load_districts(districts_csv)

    if not os.path.exists(excel_file):
        print(f"File not found: {excel_file}")
        return None

    print(f"\n{'='*60}")
    print(f"Processing: {os.path.basename(excel_file)}")
    print(f"{'='*60}")

    # Load Excel file
    try:
        df = pd.read_excel(excel_file)
        print(f"Total rows loaded: {len(df)}")
        print(f"Columns found: {', '.join(df.columns.tolist())}")
    except Exception as e:
        print(f"Error reading {excel_file}: {e}")
        return None

    # Build payment history from the data
    phone_mapping = create_phone_district_mapping()
    payment_history = build_payment_history(df, district_names)

    # Add district column
    def apply_district(row):
        return get_district_for_payment(row, district_names, phone_mapping, payment_history)

    df["district"] = df.apply(apply_district, axis=1)

    # Group by district
    district_groups = {}
    for district in df["district"].unique():
        district_data = df[df["district"] == district].copy()
        # Drop the district column from the data (it will be the filename)
        district_groups[district] = district_data

    # Create splits directory
    splits_dir = "C:/xampp/htdocs/ecashverifier/splits"
    os.makedirs(splits_dir, exist_ok=True)

    # Get base filename without path and extension
    base_name = os.path.splitext(os.path.basename(excel_file))[0]

    # Save each district as a separate file
    for district, district_data in district_groups.items():
        print(f"\nDistrict: {district} - {len(district_data)} records")

        if len(district_data) == 0:
            continue

        # Create district folder
        district_folder = os.path.join(splits_dir, district)
        os.makedirs(district_folder, exist_ok=True)

        # Save complete district data
        filename = f"{base_name}_{district}.xlsx"
        filepath = os.path.join(district_folder, filename)

        # Remove the district column before saving (it's implied by the folder)
        save_data = district_data.drop("district", axis=1)

        # Save to Excel
        with pd.ExcelWriter(filepath, engine="openpyxl") as writer:
            save_data.to_excel(writer, sheet_name="Payments", index=False)

        # Adjust column widths
        try:
            wb = load_workbook(filepath)
            ws = wb.active

            for column in ws.columns:
                max_length = 0
                column_letter = get_column_letter(column[0].column)
                for cell in column:
                    try:
                        if len(str(cell.value)) > max_length:
                            max_length = len(str(cell.value))
                    except:
                        pass
                adjusted_width = min(max_length + 2, 50)
                ws.column_dimensions[column_letter].width = adjusted_width

            wb.save(filepath)
        except Exception as e:
            print(f"Warning: Could not adjust column widths: {e}")

        file_size = os.path.getsize(filepath) / (1024 * 1024)
        print(f"  Saved: {filename} ({file_size:.2f} MB)")

        # If file is larger than 1.9 MB, split it
        if file_size > 1.9:
            print(f"  File is {file_size:.2f} MB, splitting into chunks...")
            chunks = split_into_chunks(save_data, 1.9)

            for i, chunk in enumerate(chunks, 1):
                chunk_filename = f"{base_name}_{district}_part_{i}.xlsx"
                chunk_filepath = os.path.join(district_folder, chunk_filename)

                with pd.ExcelWriter(chunk_filepath, engine="openpyxl") as writer:
                    chunk.to_excel(writer, sheet_name="Payments", index=False)

                chunk_size = os.path.getsize(chunk_filepath) / (1024 * 1024)
                print(f"    Saved: {chunk_filename} ({chunk_size:.2f} MB)")

    # Save summary report
    summary_data = []
    for district, data in district_groups.items():
        summary_data.append(
            {
                "District": district,
                "Record Count": len(data),
                "File Size (MB)": round(data.memory_usage(deep=True).sum() / (1024 * 1024), 2),
            }
        )

    summary_df = pd.DataFrame(summary_data)
    summary_df = summary_df.sort_values("Record Count", ascending=False)

    summary_file = os.path.join(splits_dir, f"{base_name}_summary_report.xlsx")
    with pd.ExcelWriter(summary_file, engine="openpyxl") as writer:
        summary_df.to_excel(writer, sheet_name="Summary", index=False)

    print(f"\nSummary report saved: {summary_file}")
    print("\nDistrict breakdown:")
    print(summary_df.to_string(index=False))

    return district_groups


def split_into_chunks(df, max_size_mb=1.9):
    """
    Split dataframe into chunks based on approximate file size
    """
    if len(df) == 0:
        return []

    # Estimate size per row
    estimated_size_per_row = df.memory_usage(deep=True).sum() / len(df) / (1024 * 1024)
    rows_per_chunk = int((max_size_mb * 0.8) / estimated_size_per_row)
    rows_per_chunk = max(1, rows_per_chunk)

    chunks = []
    for i in range(0, len(df), rows_per_chunk):
        chunk = df.iloc[i : i + rows_per_chunk]
        chunks.append(chunk)

    return chunks


# Main execution
if __name__ == "__main__":
    # Define the directory where your Excel files are located
    data_dir = "C:/xampp/htdocs/ecashverifier"

    # Find all Excel files in the directory
    excel_files = [
        f
        for f in os.listdir(data_dir)
        if f.endswith(".xlsx")
        and not f.startswith("~$")
        and not f.startswith("splits")
        and not f.endswith("_with_districts.xlsx")
        and not f.endswith("_complete.xlsx")
        and not f.endswith("summary_report.xlsx")
    ]

    if not excel_files:
        print("No Excel files found in C:/xampp/htdocs/ecashverifier")
        print("Please make sure your Excel files are in the directory")
        exit()

    print(f"Found Excel files: {excel_files}")
    print("\nProcessing files...")

    # Process each Excel file individually
    for excel_file in excel_files:
        file_path = os.path.join(data_dir, excel_file)
        try:
            process_payment_file(file_path, "districts.csv")
        except Exception as e:
            print(f"Error processing {excel_file}: {e}")
            import traceback

            traceback.print_exc()

    print(f"\n{'='*60}")
    print("ALL FILES PROCESSED SUCCESSFULLY")
    print(f"{'='*60}")
    print(f"All output files are in: {data_dir}/splits/")
    print("Each district has its own folder with the relevant data")
    print("\nTo view the summary report, open:")
    print(f"{data_dir}/splits/*_summary_report.xlsx")
