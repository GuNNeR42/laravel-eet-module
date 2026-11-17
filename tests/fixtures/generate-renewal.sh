#!/usr/bin/env bash
#
# Regenerates the fixtures in tests/fixtures/renewal (certificate renewal
# through the CA EET API, spec/12). The generated files are committed; run this
# script only when such a fixture has to change. Every run creates new keys,
# so all files produced here change together. The other fixtures
# (generate.sh) are not touched.
#
# Requirements: OpenSSL >= 3.4 (for -not_before / -not_after and -legacy).
#
set -euo pipefail

cd "$(dirname "$0")"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Must match Gunner42\LaravelEetModule\Tests\Support\CaEetApi.
P12_PASSWORD="Renew123"
PRODUCTION_POLICY="1.2.203.19122063.10.1.102.0.1"

OUT=renewal
rm -rf "$OUT"
mkdir -p "$OUT"

CA_DN="/CN=Test CA EET production/O=EET module tests/C=CZ"

# own "production" CA (stands in for CA EET)
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$WORK/ca.key" -out "$OUT/ca.pem" \
    -subj "$CA_DN" -not_before 20250101000000Z -not_after 20350101000000Z \
    -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" \
    -addext "subjectKeyIdentifier=hash"
CA_SKI="$(openssl x509 -in "$OUT/ca.pem" -noout -ext subjectKeyIdentifier | tail -1 | tr -d ' ')"

# rogue CAs with the same DN (not committed): one with its own key identifier,
# one that copies the key identifier of the real CA
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$WORK/rogue.key" -out "$WORK/rogue.pem" \
    -subj "$CA_DN" -not_before 20250101000000Z -not_after 20350101000000Z \
    -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" \
    -addext "subjectKeyIdentifier=hash"
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$WORK/rogue-same-ski.key" -out "$WORK/rogue-same-ski.pem" \
    -subj "$CA_DN" -not_before 20250101000000Z -not_after 20350101000000Z \
    -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" \
    -addext "subjectKeyIdentifier=$CA_SKI"
# another CA with a different DN
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$WORK/other-ca.key" -out "$WORK/other-ca.pem" \
    -subj "/CN=Other test CA/O=EET module tests/C=CZ" -not_before 20250101000000Z -not_after 20350101000000Z \
    -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" \
    -addext "subjectKeyIdentifier=hash"

leaf_ext() { # policy-oid (empty = no policy) -> path of the extension file
    local POLICY="$1" FILE
    FILE="$WORK/leaf-$(echo "$POLICY" | tr '.' '_').ext"
    {
        echo "basicConstraints=CA:FALSE"
        echo "keyUsage=critical,digitalSignature,nonRepudiation"
        echo "subjectKeyIdentifier=hash"
        echo "authorityKeyIdentifier=keyid:always"
        if [ -n "$POLICY" ]; then
            echo "certificatePolicies=$POLICY"
        fi
    } > "$FILE"
    echo "$FILE"
}

issue() { # name cn serial(decimal) not_before not_after policy [ca-cert ca-key]
    local NAME="$1" CN="$2" SERIAL="$3" FROM="$4" TO="$5" POLICY="$6"
    local CA_CERT="${7:-$OUT/ca.pem}" CA_KEY="${8:-$WORK/ca.key}"
    openssl req -newkey rsa:2048 -nodes -keyout "$WORK/$NAME.key" -out "$WORK/$NAME.csr" -subj "/CN=$CN/C=CZ"
    openssl x509 -req -in "$WORK/$NAME.csr" -CA "$CA_CERT" -CAkey "$CA_KEY" \
        -set_serial "$SERIAL" -not_before "$FROM" -not_after "$TO" \
        -extfile "$(leaf_ext "$POLICY")" -sha256 -out "$WORK/$NAME.crt"
    openssl x509 -in "$WORK/$NAME.crt" -out "$WORK/$NAME.cert.pem"
}

# PEM: certificate + unencrypted key + chain (renewed certificates)
pem() { # name
    local NAME="$1"
    cat "$WORK/$NAME.cert.pem" > "$OUT/$NAME.pem"
    openssl pkey -in "$WORK/$NAME.key" >> "$OUT/$NAME.pem"
    cat "$OUT/ca.pem" >> "$OUT/$NAME.pem"
}

# modern PKCS#12 (AES-256, MAC SHA-256) as the CA EET API could return it
p12() { # name [chain]
    local NAME="$1" CHAIN="${2:-$OUT/ca.pem}"
    openssl pkcs12 -export -in "$WORK/$NAME.cert.pem" -inkey "$WORK/$NAME.key" -certfile "$CHAIN" \
        -passout "pass:$P12_PASSWORD" -keypbe AES-256-CBC -certpbe AES-256-CBC -macalg sha256 \
        -out "$OUT/$NAME.p12"
}

# ---------------------------------------------------------------------------
# Renewed certificates (production policy), validTo chosen around the
# renewal window of 21 days relative to the frozen moment 2026-11-19 00:00 UTC
# ---------------------------------------------------------------------------
issue CZ00000019-until-2026-12-10 CZ00000019 7001 20251210000000Z 20261210000000Z "$PRODUCTION_POLICY"
issue CZ00000019-until-2026-12-11 CZ00000019 7002 20251211000000Z 20261211000000Z "$PRODUCTION_POLICY"
issue CZ00000019-until-2026-12-05 CZ00000019 7003 20251205000000Z 20261205000000Z "$PRODUCTION_POLICY"
issue CZ00000019-until-2027-06-30 CZ00000019 7004 20260630000000Z 20270630000000Z "$PRODUCTION_POLICY"
issue CZ683555118-until-2026-12-10 CZ683555118 7005 20251210000000Z 20261210000000Z "$PRODUCTION_POLICY"
# other production OID under 1.2.203.19122063.10.1. and a near miss (10.10)
issue CZ00000019-policy-10-1-other CZ00000019 7006 20251210000000Z 20261210000000Z "1.2.203.19122063.10.1.5"
issue CZ00000019-policy-10-10 CZ00000019 7007 20251210000000Z 20261210000000Z "1.2.203.19122063.10.10.1"

for NAME in CZ00000019-until-2026-12-10 CZ00000019-until-2026-12-11 CZ00000019-until-2026-12-05 \
    CZ00000019-until-2027-06-30 CZ683555118-until-2026-12-10 CZ00000019-policy-10-1-other CZ00000019-policy-10-10; do
    pem "$NAME"
done

# ---------------------------------------------------------------------------
# Successors returned by claim-download (successors of *-until-2026-12-10)
# ---------------------------------------------------------------------------
# matching successor; serial 24961511666821687692 (> 2^64, decimal in the API)
issue successor CZ00000019 24961511666821687692 20261118000000Z 20271119000000Z "$PRODUCTION_POLICY"
p12 successor
# the same certificate as a legacy PKCS#12 (RC2-40 / 3DES)
openssl pkcs12 -export -legacy -in "$WORK/successor.cert.pem" -inkey "$WORK/successor.key" -certfile "$OUT/ca.pem" \
    -passout "pass:$P12_PASSWORD" -out "$OUT/successor.legacy.p12"
# the same certificate without the private key
openssl pkcs12 -export -nokeys -in "$WORK/successor.cert.pem" -certfile "$OUT/ca.pem" \
    -passout "pass:$P12_PASSWORD" -keypbe AES-256-CBC -certpbe AES-256-CBC -macalg sha256 \
    -out "$OUT/successor-without-key.p12"
# different CN
issue successor-other-cn CZ683555118 8002 20261118000000Z 20271119000000Z "$PRODUCTION_POLICY"
p12 successor-other-cn
# validTo equal to the renewed certificate (not later)
issue successor-same-valid-to CZ00000019 8003 20261118000000Z 20261210000000Z "$PRODUCTION_POLICY"
p12 successor-same-valid-to
# successor of CZ683555118-until-2026-12-10
issue successor-CZ683555118 CZ683555118 8004 20261118000000Z 20271119000000Z "$PRODUCTION_POLICY"
p12 successor-CZ683555118

# issuer checks (Q71): all CN CZ00000019, later validTo, matching validity
# same issuer DN, different key and key identifier
issue successor-rogue-ca CZ00000019 8005 20261118000000Z 20271119000000Z "$PRODUCTION_POLICY" "$WORK/rogue.pem" "$WORK/rogue.key"
p12 successor-rogue-ca "$WORK/rogue.pem"
# same issuer DN and key identifier, but signed by another key
issue successor-rogue-same-aki CZ00000019 8006 20261118000000Z 20271119000000Z "$PRODUCTION_POLICY" "$WORK/rogue-same-ski.pem" "$WORK/rogue-same-ski.key"
p12 successor-rogue-same-aki "$WORK/rogue-same-ski.pem"
# another issuer DN
issue successor-other-issuer CZ00000019 8007 20261118000000Z 20271119000000Z "$PRODUCTION_POLICY" "$WORK/other-ca.pem" "$WORK/other-ca.key"
p12 successor-other-issuer "$WORK/other-ca.pem"
# no certificate policy (issued by the real CA)
issue successor-no-policy CZ00000019 8008 20261118000000Z 20271119000000Z ""
p12 successor-no-policy
# playground policy (issued by the real CA)
issue successor-playground-policy CZ00000019 8009 20261118000000Z 20271119000000Z "1.2.203.19122063.10.2.102.0.1"
p12 successor-playground-policy
# self-signed
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$WORK/successor-self-signed.key" -out "$WORK/successor-self-signed.cert.pem" \
    -subj "/CN=CZ00000019/C=CZ" -set_serial 8010 -not_before 20261118000000Z -not_after 20271119000000Z \
    -addext "certificatePolicies=$PRODUCTION_POLICY" -addext "keyUsage=critical,digitalSignature,nonRepudiation"
openssl pkcs12 -export -in "$WORK/successor-self-signed.cert.pem" -inkey "$WORK/successor-self-signed.key" \
    -passout "pass:$P12_PASSWORD" -keypbe AES-256-CBC -certpbe AES-256-CBC -macalg sha256 \
    -out "$OUT/successor-self-signed.p12"

# chain variants of the renewed certificate (Q74)
# the issuer with the same key and DN, but without key identifiers
openssl req -x509 -new -key "$WORK/ca.key" -out "$WORK/ca-without-ski.pem" \
    -subj "$CA_DN" -not_before 20250101000000Z -not_after 20350101000000Z \
    -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" \
    -addext "subjectKeyIdentifier=none" -addext "authorityKeyIdentifier=none"
NAME=CZ00000019-until-2026-12-10
# without a chain
{ cat "$WORK/$NAME.cert.pem"; openssl pkey -in "$WORK/$NAME.key"; } > "$OUT/$NAME.without-chain.pem"
# chain with the issuer re-issued without SKI
{ cat "$WORK/$NAME.cert.pem"; openssl pkey -in "$WORK/$NAME.key"; cat "$WORK/ca-without-ski.pem"; } > "$OUT/$NAME.chain-without-ski.pem"
# chain with only a rogue CA of the same DN (does not verify the renewed certificate)
{ cat "$WORK/$NAME.cert.pem"; openssl pkey -in "$WORK/$NAME.key"; cat "$WORK/rogue-same-ski.pem"; } > "$OUT/$NAME.rogue-chain.pem"

# production OID only inside policy qualifiers of a playground policy (R90)
cat > "$WORK/qualifier.ext" <<'EXT'
[leaf]
basicConstraints=CA:FALSE
keyUsage=critical,digitalSignature,nonRepudiation
subjectKeyIdentifier=hash
authorityKeyIdentifier=keyid:always
certificatePolicies=@policy

[policy]
policyIdentifier=1.2.203.19122063.10.2.102.0.1
CPS.1="http://example.test/cps/1.2.203.19122063.10.1.1"
userNotice.1=@notice

[notice]
explicitText="Policy: 1.2.203.19122063.10.1.1"
EXT
openssl req -newkey rsa:2048 -nodes -keyout "$WORK/qualifier.key" -out "$WORK/qualifier.csr" -subj "/CN=CZ00000019/C=CZ"
openssl x509 -req -in "$WORK/qualifier.csr" -CA "$OUT/ca.pem" -CAkey "$WORK/ca.key" -set_serial 7008 \
    -not_before 20251210000000Z -not_after 20261210000000Z -extfile "$WORK/qualifier.ext" -extensions leaf \
    -sha256 -out "$WORK/qualifier.crt"
{ openssl x509 -in "$WORK/qualifier.crt"; openssl pkey -in "$WORK/qualifier.key"; cat "$OUT/ca.pem"; } > "$OUT/CZ00000019-production-oid-in-qualifier.pem"

echo "Renewal fixtures regenerated."
