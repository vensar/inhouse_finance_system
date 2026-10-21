#!/usr/bin/env sh
# Illustrative mTLS transport options. Do not put real secrets in this file.
# Replace the URL, headers, and body with the provider-approved contract.

curl --fail-with-body --silent --show-error \
  --connect-timeout 10 \
  --max-time 30 \
  --cert "{{CLIENT_CERTIFICATE_PATH}}" \
  --key "{{CLIENT_PRIVATE_KEY_PATH}}" \
  --cacert "{{PROVIDER_CA_CHAIN_PATH}}" \
  -H "Authorization: Bearer {{ACCESS_TOKEN}}" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-Id: {{UUID}}" \
  --data '{"msisdn":"256772123456","submittedName":"SAMPLE CUSTOMER"}' \
  "{{PROVIDER_BASE_URL}}/{{NAME_VERIFICATION_PATH}}"

