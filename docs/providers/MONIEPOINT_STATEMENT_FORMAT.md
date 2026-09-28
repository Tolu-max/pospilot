# Moniepoint statement format verification

## Verification result

**A real statement file was not obtained or inspected.** The signed-in account's web dashboard Accounts and All Transactions pages did not expose a usable statement export action. The account's mobile-app export flow was not inspected. No customer rows or account records were downloaded or added to the repository.

## Verified from official Moniepoint material

Moniepoint's [account information support topic](https://support.moniepoint.com/topics/account-information-and-updates-234) describes a business statement export in the mobile app: open **History** beside the account balance, choose **Export**, select a date range and a **Standard** or **Extended** statement, then export as **Excel** or **PDF**. This describes availability and broad file types only; it does not verify the actual file extension, workbook sheets, CSV support, or column headers for the account reviewed.

Moniepoint's [statement export overview](https://moniepoint.com/blog/account-statement-export-made-easier) also describes Standard, Extended, Daily Business Summary, and Daily Terminal Summary options. Those labels do not establish the schema of a real provider export.

## File and field details

| Detail | Verified result |
|---|---|
| Real file type/extension | Not verified |
| Export options described publicly | Excel and PDF |
| Exact column names | Not verified |
| Date/time format or timezone | Not verified |
| Status values | Not verified |
| Stable account/business/terminal identifier in export | Not verified |
| Provider-fee field | Not verified |
| Customer/personal fields present | Not verified; no real file was opened |
| Existing parser works unchanged on a real statement | Not verified |

## Comparison with the existing importer

`MoniepointCsvImporter` accepts CSV headers matching common aliases for transaction reference, amount, customer charge, provider fee, status, transaction date/time, terminal identifier, and transaction type. This is implementation evidence only. It is not evidence that Moniepoint exports CSV or uses those column names. It does not parse an unverified Excel or PDF export by itself.

The importer should remain unclaimed as compatible with a real Moniepoint statement until a provider-issued file is obtained, its header/schema is inspected without retaining customer rows, and a sanitized local test confirms normalization and idempotency. A real provider-fee column must be confirmed before it is used to upgrade provisional earnings.

## Safe follow-up needed

Obtain a statement using the account owner's approved local data-handling process. Keep the original outside the repository, inspect only its file metadata and headers/column names, redact or discard personal rows, and compare the sanitized schema against the existing importer. Do not use a statement sample containing real customer information as a fixture.
