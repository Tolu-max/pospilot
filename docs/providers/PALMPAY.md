# PalmPay direct connector

## Research decision

The official [PalmPay developer documentation](https://docs.palmpay.com/) lists payment and value-added products. Its [POS notification product introduction](https://docs.palmpay.com/#/en-us/value-added-services/pos/notification/instruction) describes bank/PalmPay partner integration for transaction notifications and says provider product onboarding/keys are prerequisites. The material reviewed does not document a read API by which an ordinary PalmPay POS agent/business can authorize access to historical transactions on an existing POS terminal.

We did not infer existing-terminal access from checkout, payout, virtual account, or partner notification APIs. Fees, settlement data, balance access, terminal query, eligible agent credentials, and sandbox access for this requested use case remain unverified.

## POSPilot status

| Stage | Status |
|---|---|
| Documented | Partner-oriented POS notification and other payment products; not ordinary-agent POS history access. |
| Implemented | No direct connector. CSV and separate Gmail statement ingestion remain available. |
| Automated-tested | No direct API contract. Existing import tests are independent. |
| Sandbox-tested | No. |
| Live-tested | No. No verified PalmPay credentials or terminal. |
| Capability / flag | Direct connection `coming_later`; `FEATURE_PALMPAY_DIRECT=false`. |

PalmPay direct stays disabled pending explicit confirmation/documentation of the existing-terminal read use case. See [access request preparation](PALMPAY_ACCESS_REQUEST.md). Do not integrate payment initiation or payout functionality as a substitute.
