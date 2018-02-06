# SOAP importer plugin for CiviBanking
Currently this is only usable for the e-Boekhouden.nl SOAP api.

- Settings: go to Banking > E-boekhouden settings (or civicrm/e-boekhouden)
- Configuration: go to Banking > Configuration Manager
- Settings (cron) job: Manage > System Settings > Scheduled Jobs
- Documentation CiviBanking: https://github.com/Project60/org.project60.banking/wiki

_Example JSON configuration for the importer plugin:_
```
{
  "rules": [
    {
      "from": "MutatieRegels",
      "to": [
        {
          "from": "cMutatieListRegel",
          "to": [
            {
              "from": "BedragInvoer",
              "to": "amount",
              "type": "amount"
            },
            {
              "from": "TegenrekeningCode",
              "to": "purpose",
              "type": "set"
            }
          ],
          "type": "object"
        }
      ],
      "type": "object"
    },
    {
      "if": "equalto:1010",
      "from": "Rekening",
      "to": "payment_instrument_id",
      "type": "replace:1010:5"
    },
    {
      "if": "equalto:1000",
      "from": "Rekening",
      "to": "payment_instrument_id",
      "type": "replace:1000:3"
    },
    {
      "if": "equalto:GeldUitgegeven",
      "from": "Soort",
      "to": "_tmp",
      "type": "set"
    },
    {
      "from": "amount",
      "to": "_tmp",
      "type": "append:"
    },
    {
      "from": "_tmp",
      "to": "amount",
      "type": "replace:GeldUitgegeven:-"
    },
    {
      "from": "Datum",
      "to": "booking_date",
      "type": "strtotime:Y-m-d\\TH:i:s"
    },
    {
      "from": "Datum",
      "to": "value_date",
      "type": "strtotime:Y-m-d\\TH:i:s"
    },
    {
      "from": "Omschrijving",
      "to": "description",
      "type": "set"
    },
    {
      "from": "Omschrijving",
      "to": "description",
      "warn": 0,
      "type": "regex:#(.*) NONREF\\z#"
    },
    {
      "comment": "extract IBAN",
      "from": "description",
      "to": "_party_IBAN",
      "warn": 0,
      "type": "regex:#([A-Z]{2}[0-9]{2}[A-Z0-9]*)\\b#"
    },
    {
      "comment": "extract BIC",
      "from": "description",
      "to": "_party_BIC",
      "warn": 0,
      "type": "regex:#\\b([A-Z]{6}[A-Z0-9]{2})\\b#"
    },
    {
      "comment": "extract Contact id",
      "from": "description",
      "to": "contact_id",
      "warn": 0,
      "type": "regex:#\\b[0-9]{1}8[0-1]{1}[0-9]{1}0000([0-9]{7})0#"
    },
    {
      "comment": "extract Name",
      "from": "description",
      "to": "name",
      "warn": 0,
      "type": "regex:#\\b[A-Z]{6}[A-Z0-9]{2} (.*)\\z#"
    },
    {
      "comment": "extract Name",
      "from": "name",
      "to": "name",
      "warn": 0,
      "type": "regex:#\\A(?P<name>.*)?\\b[0-9]{1}8[0-1]{1}[0-9]{1}0000([0-9]{7})0\\z#"
    }
  ]
}
```
