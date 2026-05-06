# Contract Template

Global contract template for generating rental agreements.

## Template Location

`templates/documents/contract_template.docx`

## Available Placeholders

Use `${PLACEHOLDER}` syntax in the DOCX template. Substitution is implemented in
`App\Service\ContractDocumentGenerator`. Make placeholder runs bold in the
template — values inherit the run's formatting, so making the placeholder bold
makes every substituted value render bold.

| Placeholder | Description | Example |
|-------------|-------------|---------|
| `${TENANT_INFO}` | Multiline tenant block (person or company variant). Includes name/company, IČO, DIČ, address, email, phone. Newlines are rendered as line breaks. | Jméno: Jan Novák\nNar. 01.01.1990\nBytem: Hlavní 123, 110 00 Praha\nEmail: jan@example.com\nTelefon: +420 123 456 789 |
| `${STORAGE_DESCRIPTION}` | Storage type, number, and inner dimensions | Small Box č. A1 (100 × 200 × 150 cm) |
| `${RENTAL_DURATION_TEXT}` | Full sentence with start/end dates | Nájem se sjednává na dobu určitou, a to od 15.01.2024 do 15.02.2024 |
| `${CONTRACT_NUMBER}` | Generated contract number | 2024-0101-A1B2C3D4 |
| `${CONTRACT_CITY}` | City of the storage location | Praha |
| `${CONTRACT_DATE}` | Contract creation date | 01.01.2024 |
| `${SIGNING_PLACE}` | Place where contract was signed (defaults to storage city) | Praha |
| `${SIGNING_DATE}` | Date of signature | 01.01.2024 |
| `${SIGNATURE}` | Tenant's electronic signature image (PNG, 250×100). Cleared if missing. | (image) |

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
