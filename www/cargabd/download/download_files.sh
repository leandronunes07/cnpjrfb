#!/bin/bash

#Cores
RED='\033[0;31m'
NC='\033[0m' # No Color

BASE_URL="https://arquivos.receitafederal.gov.br/dados/cnpj/dados_abertos_cnpj"
DEST_DIR="/var/www/html/cargabd/download"
USER_AGENT="Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:94.0) Gecko/20100101 Firefox/94.0"

mkdir -p "$DEST_DIR"

echo "🔎 Buscando a pasta mais recente em $BASE_URL ..."

# Dynamic Folder Discovery
LATEST_DIR=$(curl -s -k -L -A "$USER_AGENT" "$BASE_URL/" | grep -o 'href="[0-9]\{4\}-[0-9]\{2\}/"' | cut -d'"' -f2 | sort | tail -n 1)

if [ -z "$LATEST_DIR" ]; then
    printf "${RED}Error: Could not find date folders (YYYY-MM) in $BASE_URL${NC}\n" >&2
    exit 1
fi

FULL_URL="${BASE_URL}/${LATEST_DIR}"
echo "📂 Pasta encontrada: $LATEST_DIR"
echo "🔗 URL Base para download: $FULL_URL"

# Get ZIP URLs from that folder
get_zip_urls() {
    local urls
    if ! urls=$(curl -s -k -L -A "$USER_AGENT" "$FULL_URL" | grep -Eo 'href="([^"]+\.zip)"' | awk -F'"' '{print $2}'); then
        printf "${RED}Error: Failed to retrieve ZIP URLs.${NC}\n" >&2
        return 1
    fi

    # Prepend the base URL
    urls=$(printf "%s\n" "$urls" | sed "s|^|$FULL_URL|")
    printf "%s\n" "$urls"
}

download_zips() {
    local zip_urls
    if ! zip_urls=$(get_zip_urls); then
        return 1
    fi

    while IFS= read -r zip_url; do
        local file_name; file_name=$(basename "$zip_url")

        printf "Downloading %s...\n" "$file_name"
        # Using curl -O (remote name) might be tricky with full path if not in CWD, so we use -o
        if ! curl -L -k -A "$USER_AGENT" -o "$DEST_DIR/$file_name" "$zip_url"; then
             printf "${RED}Error: Failed to download %s${NC}\n" "$file_name" >&2
             continue
        fi

        printf "Downloaded %s successfully.\n" "$file_name"
    done <<< "$zip_urls"
}

main() {
    if ! download_zips; then
        printf "${RED}Error: ZIP download process failed.${NC}\n" >&2
        return 1
    fi
    echo "🎉 All downloads completed from $LATEST_DIR."
}

main "$@"