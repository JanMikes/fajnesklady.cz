# Contract Template

Global contract template for generating rental agreements.

## Template Location

`templates/documents/contract_template.docx`

## Available Placeholders

Use `${PLACEHOLDER}` syntax in the DOCX template.

### Tenant Information

| Placeholder | Description | Example |
|-------------|-------------|---------|
| `${TENANT_NAME}` | Full name | Jan Novák |
| `${TENANT_EMAIL}` | Email address | jan@example.com |
| `${TENANT_PHONE}` | Phone number | +420 123 456 789 |
| `${TENANT_COMPANY}` | Company name | Firma s.r.o. |
| `${TENANT_ICO}` | Company ID (IČO) | 12345678 |
| `${TENANT_DIC}` | VAT ID (DIČ) | CZ12345678 |
| `${TENANT_BILLING_ADDRESS}` | Billing address | Ulice 123, 110 00 Praha |

### Storage Information

| Placeholder | Description | Example |
|-------------|-------------|---------|
| `${STORAGE_NUMBER}` | Box number | A1 |
| `${STORAGE_TYPE}` | Storage type name | Small Box |
| `${STORAGE_DIMENSIONS}` | Inner dimensions (w × h × d) | 100 × 200 × 150 cm |

### Place Information

| Placeholder | Description | Example |
|-------------|-------------|---------|
| `${PLACE_NAME}` | Warehouse name | Sklad Praha |
| `${PLACE_ADDRESS}` | Full address | Skladová 123, 110 00 Praha |

### Rental Details

| Placeholder | Description | Example |
|-------------|-------------|---------|
| `${START_DATE}` | Rental start date | 15.01.2024 |
| `${END_DATE}` | Rental end date | 15.02.2024 or "Na dobu neurčitou" |
| `${RENTAL_TYPE}` | Type of rental | "Doba určitá" / "Doba neurčitá" |
| `${PRICE}` | Total price | 350,00 Kč |

### Contract Metadata

| Placeholder | Description | Example |
|-------------|-------------|---------|
| `${CONTRACT_DATE}` | Contract creation date | 01.01.2024 |
| `${CONTRACT_NUMBER}` | Generated contract number | 2024-0101-A1B2C3D4 |

## Editing the Template

1. Open `contract_template.docx` in Microsoft Word or LibreOffice
2. Edit styling, layout, and text as needed
3. Keep placeholder syntax exactly as `${PLACEHOLDER_NAME}`
4. Save the file

## Generated Documents

Generated contracts are saved to `var/contracts/` with filename format:
```
contract_{uuid}_{date}.docx
```
