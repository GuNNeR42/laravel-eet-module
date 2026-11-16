#!/usr/bin/env bash
#
# Regenerates the test fixtures in this directory. The generated files are
# committed; run this script only when a fixture has to change. Every run
# creates new keys, so all fixtures produced here change together.
#
# Requirements: OpenSSL >= 3.4 (for -not_before / -not_after and -legacy).
#
set -euo pipefail

cd "$(dirname "$0")"
ROOT_DIR="$(cd ../.. && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PLAYGROUND_PASSWORD="aaaa1111"
PEM_PASSWORD="Heslo123"
P12_PASSWORD="Abcd1234"

# ---------------------------------------------------------------------------
# 1. Playground cash register certificates (CA EET, legacy PKCS#12)
# ---------------------------------------------------------------------------
mkdir -p playground
unzip -o -q "$ROOT_DIR/spec/zadani/mfcr/CAEET_Playground_2026_v1.zip" -d playground

for EIC in CZ00000019 CZ683555118 CZ8551015704; do
    P12="playground/CA_EET-Playground-$EIC.p12"
    # certificate + plain private key + chain
    openssl pkcs12 -legacy -in "$P12" -passin "pass:$PLAYGROUND_PASSWORD" -nodes -out "$WORK/$EIC.all.pem"
    openssl pkcs12 -legacy -in "$P12" -passin "pass:$PLAYGROUND_PASSWORD" -clcerts -nokeys -out "$WORK/$EIC.cert.pem"
    openssl pkcs12 -legacy -in "$P12" -passin "pass:$PLAYGROUND_PASSWORD" -cacerts -nokeys -out "$WORK/$EIC.chain.pem"
    openssl pkcs12 -legacy -in "$P12" -passin "pass:$PLAYGROUND_PASSWORD" -nocerts -nodes -out "$WORK/$EIC.key.pem"

    openssl x509 -in "$WORK/$EIC.cert.pem" -out "playground/$EIC.cert.pem"
    openssl pkey -in "$WORK/$EIC.key.pem" -out "playground/$EIC.key.pem"
    openssl pkey -in "$WORK/$EIC.key.pem" -aes256 -passout "pass:$PEM_PASSWORD" -out "playground/$EIC.key.encrypted.pem"

    # plain PEM: certificate, unencrypted key, chain
    cat "playground/$EIC.cert.pem" "playground/$EIC.key.pem" > "playground/$EIC.pem"
    awk '/BEGIN CERTIFICATE/,/END CERTIFICATE/' "$WORK/$EIC.chain.pem" >> "playground/$EIC.pem"
    # PEM with the key encrypted by $PEM_PASSWORD
    cat "playground/$EIC.cert.pem" "playground/$EIC.key.encrypted.pem" > "playground/$EIC.encrypted.pem"
    awk '/BEGIN CERTIFICATE/,/END CERTIFICATE/' "$WORK/$EIC.chain.pem" >> "playground/$EIC.encrypted.pem"
    # modern (non-legacy) PKCS#12, AES-256 + SHA-256 MAC
    openssl pkcs12 -export -in "playground/$EIC.cert.pem" -inkey "playground/$EIC.key.pem" \
        -certfile "$WORK/$EIC.chain.pem" -passout "pass:$P12_PASSWORD" \
        -keypbe AES-256-CBC -certpbe AES-256-CBC -macalg sha256 -out "playground/$EIC.modern.p12"
done

# certificate of CZ00000019 with the key of CZ683555118
cat playground/CZ00000019.cert.pem playground/CZ683555118.key.pem > playground/CZ00000019.foreign-key.pem

# ---------------------------------------------------------------------------
# 2. Own cash register certificates (dates not covered by the playground)
# ---------------------------------------------------------------------------
mkdir -p cash-register
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$WORK/cr-ca.key" -out cash-register/ca.pem \
    -subj "/CN=Test cash register CA/C=CZ" -not_before 20250101000000Z -not_after 20350101000000Z \
    -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign"

cat > "$WORK/cr-leaf.ext" <<'EOF'
basicConstraints=CA:FALSE
keyUsage=critical,digitalSignature,nonRepudiation
EOF

cash_register_cert() { # name cn not_before not_after
    local NAME="$1" CN="$2" FROM="$3" TO="$4"
    openssl req -newkey rsa:2048 -nodes -keyout "$WORK/$NAME.key" -out "$WORK/$NAME.csr" -subj "/CN=$CN/C=CZ"
    openssl x509 -req -in "$WORK/$NAME.csr" -CA cash-register/ca.pem -CAkey "$WORK/cr-ca.key" \
        -set_serial "0x$(openssl rand -hex 8)" -not_before "$FROM" -not_after "$TO" \
        -extfile "$WORK/cr-leaf.ext" -sha256 -out "$WORK/$NAME.crt"
    openssl x509 -in "$WORK/$NAME.crt" -out "cash-register/$NAME.pem"
    openssl pkey -in "$WORK/$NAME.key" >> "cash-register/$NAME.pem"
}

cash_register_cert CZ00000019-until-2027-12-31 CZ00000019 20260101000000Z 20271231235959Z
cash_register_cert CZ683555118-until-2027-12-31 CZ683555118 20260101000000Z 20271231235959Z
cash_register_cert CZ00000019-until-2027-10-01-a CZ00000019 20260101000000Z 20271001000000Z
cash_register_cert CZ00000019-until-2027-10-01-b CZ00000019 20260101000000Z 20271001000000Z
cash_register_cert CZ00000019-until-2027-03-01-noon CZ00000019 20260101000000Z 20270301120000Z
cash_register_cert CZ00000019-expired-2025 CZ00000019 20250101000000Z 20251231235959Z
cash_register_cert CZ00000019-from-2027-01-01 CZ00000019 20270101000000Z 20271231235959Z
cash_register_cert TEST-cn TEST 20260101000000Z 20271231235959Z

# ---------------------------------------------------------------------------
# 3. Own CA for signed EET responses (used through response_trust_store)
# ---------------------------------------------------------------------------
mkdir -p response-ca
cat > "$WORK/ca.ext" <<'EOF'
basicConstraints=critical,CA:TRUE
keyUsage=critical,keyCertSign,cRLSign
subjectKeyIdentifier=hash
authorityKeyIdentifier=keyid:always
EOF
cat > "$WORK/ca-no-keycertsign.ext" <<'EOF'
basicConstraints=critical,CA:TRUE
keyUsage=critical,digitalSignature
subjectKeyIdentifier=hash
authorityKeyIdentifier=keyid:always
EOF
cat > "$WORK/leaf.ext" <<'EOF'
basicConstraints=CA:FALSE
keyUsage=critical,digitalSignature,nonRepudiation,keyEncipherment
extendedKeyUsage=emailProtection,clientAuth
subjectKeyIdentifier=hash
authorityKeyIdentifier=keyid:always
EOF

openssl req -x509 -newkey rsa:2048 -nodes -keyout response-ca/root.key -out response-ca/root.pem \
    -subj "/CN=Test Root CA/O=EET module tests/C=CZ" -not_before 20250101000000Z -not_after 20450101000000Z \
    -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" \
    -addext "subjectKeyIdentifier=hash"

intermediate() { # name subject extfile
    local NAME="$1" SUBJ="$2" EXT="$3"
    openssl req -newkey rsa:2048 -nodes -keyout "response-ca/$NAME.key" -out "$WORK/$NAME.csr" -subj "$SUBJ"
    openssl x509 -req -in "$WORK/$NAME.csr" -CA response-ca/root.pem -CAkey response-ca/root.key \
        -set_serial "0x$(openssl rand -hex 8)" -not_before 20250101000000Z -not_after 20350101000000Z \
        -extfile "$EXT" -sha256 -out "response-ca/$NAME.pem"
}

intermediate intermediate "/CN=Test Public CA/O=EET module tests/C=CZ" "$WORK/ca.ext"
# same subject as the trusted intermediate, different key, NOT in any trust store
openssl req -x509 -newkey rsa:2048 -nodes -keyout response-ca/rogue-intermediate.key -out response-ca/rogue-intermediate.pem \
    -subj "/CN=Test Public CA/O=EET module tests/C=CZ" -not_before 20250101000000Z -not_after 20350101000000Z \
    -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" \
    -addext "subjectKeyIdentifier=hash"
intermediate intermediate-no-keycertsign "/CN=Test CA without keyCertSign/O=EET module tests/C=CZ" "$WORK/ca-no-keycertsign.ext"

PG_CN="NEOSTRE - playground prostredi EET"
ORG="Generalni financni reditelstvi"

leaf() { # name subject issuer keyspec not_before not_after
    local NAME="$1" SUBJ="$2" ISSUER="$3" KEYSPEC="$4" FROM="$5" TO="$6"
    openssl req -newkey "$KEYSPEC" -nodes -keyout "response-ca/$NAME.key" -out "$WORK/$NAME.csr" -subj "$SUBJ"
    openssl x509 -req -in "$WORK/$NAME.csr" -CA "response-ca/$ISSUER.pem" -CAkey "response-ca/$ISSUER.key" \
        -set_serial "0x$(openssl rand -hex 8)" -not_before "$FROM" -not_after "$TO" \
        -extfile "$WORK/leaf.ext" -sha256 -out "response-ca/$NAME.pem"
}

leaf signer-playground "/CN=$PG_CN/C=CZ/O=$ORG/organizationIdentifier=NTRCZ-72080043" intermediate rsa:2048 20260101000000Z 20271231235959Z
leaf signer-production "/CN=EET produkcni prostredi/C=CZ/O=$ORG/organizationIdentifier=NTRCZ-72080043" intermediate rsa:2048 20260101000000Z 20271231235959Z
leaf signer-wrong-org "/CN=$PG_CN/C=CZ/O=Jina firma/organizationIdentifier=NTRCZ-12345678" intermediate rsa:2048 20260101000000Z 20271231235959Z
leaf signer-two-orgids "/CN=$PG_CN/C=CZ/O=$ORG/organizationIdentifier=NTRCZ-72080043/organizationIdentifier=NTRCZ-72080043" intermediate rsa:2048 20260101000000Z 20271231235959Z
leaf signer-two-cns "/CN=$PG_CN/CN=$PG_CN/C=CZ/O=$ORG/organizationIdentifier=NTRCZ-72080043" intermediate rsa:2048 20260101000000Z 20271231235959Z
leaf signer-other-cn "/CN=Jiny podepisujici certifikat/C=CZ/O=$ORG/organizationIdentifier=NTRCZ-72080043" intermediate rsa:2048 20260101000000Z 20271231235959Z
leaf signer-ec "/CN=$PG_CN/C=CZ/O=$ORG/organizationIdentifier=NTRCZ-72080043" intermediate ec:<(openssl ecparam -name prime256v1) 20260101000000Z 20271231235959Z
leaf signer-rsa1024 "/CN=$PG_CN/C=CZ/O=$ORG/organizationIdentifier=NTRCZ-72080043" intermediate rsa:1024 20260101000000Z 20271231235959Z
leaf signer-2025 "/CN=$PG_CN/C=CZ/O=$ORG/organizationIdentifier=NTRCZ-72080043" intermediate rsa:2048 20250101000000Z 20251231235959Z
leaf signer-rogue "/CN=$PG_CN/C=CZ/O=$ORG/organizationIdentifier=NTRCZ-72080043" rogue-intermediate rsa:2048 20260101000000Z 20271231235959Z
leaf signer-under-no-keycertsign "/CN=$PG_CN/C=CZ/O=$ORG/organizationIdentifier=NTRCZ-72080043" intermediate-no-keycertsign rsa:2048 20260101000000Z 20271231235959Z

# trust stores
cat response-ca/root.pem response-ca/intermediate.pem > response-ca/trust-store.pem
cat response-ca/root.pem response-ca/intermediate-no-keycertsign.pem > response-ca/trust-store-no-keycertsign.pem
cat response-ca/root.pem response-ca/intermediate.pem response-ca/signer-playground.pem > response-ca/trust-store-with-leaf.pem
cat response-ca/intermediate.pem > response-ca/trust-store-without-anchor.pem
cat response-ca/root.pem > response-ca/trust-store-without-intermediate.pem
cp response-ca/trust-store.pem response-ca/trust-store-11.pem
cp response-ca/trust-store.pem response-ca/trust-store-3.pem
for I in 1 2 3 4 5 6 7 8 9; do
    openssl req -x509 -newkey rsa:2048 -nodes -keyout "$WORK/extra-$I.key" -out "$WORK/extra-$I.pem" \
        -subj "/CN=Extra Root $I/C=CZ" -not_before 20250101000000Z -not_after 20450101000000Z \
        -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign"
    cat "$WORK/extra-$I.pem" >> response-ca/trust-store-11.pem
done
cat "$WORK/extra-1.pem" >> response-ca/trust-store-3.pem

# ---------------------------------------------------------------------------
# 4. Copies of the reference documents
# ---------------------------------------------------------------------------
mkdir -p pg-vzorek xsd
cp "$ROOT_DIR"/spec/zadani/pg-vzorek/* pg-vzorek/
cp "$ROOT_DIR/spec/zadani/mfcr/EETXMLSchema.xsd" xsd/EETXMLSchema.xsd

echo "Fixtures regenerated."
