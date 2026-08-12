#!/usr/bin/env bash
#
# Deze downloads zijn alleen nog nodig bij een verse checkout waarin
# assets/js/vendor/ en assets/css/vendor/ leeg zijn. Normaal gesproken
# staan fabric.min.js, nouislider.min.js en nouislider.min.css al in de
# repo (zie de !/assets/js/vendor/ en !/assets/css/vendor/ uitzonderingen
# in .gitignore) en hoef je dit script niet te draaien.
#
# Run vanuit assets/js/vendor/:
#   bash download-vendor.sh

set -euo pipefail

curl -fsSL "https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js" \
    -o "fabric.min.js"

curl -fsSL "https://cdn.jsdelivr.net/npm/nouislider@15.8.1/dist/nouislider.min.js" \
    -o "nouislider.min.js"

mkdir -p "../../css/vendor"
curl -fsSL "https://cdn.jsdelivr.net/npm/nouislider@15.8.1/dist/nouislider.min.css" \
    -o "../../css/vendor/nouislider.min.css"

echo "Vendor assets downloaded."
