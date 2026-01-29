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

    if ! zip_urls=$(get_zip_urls); then
        return 1
    fi

    # Count total
    local total_files=$(echo "$zip_urls" | wc -l)
    local current=0

    while IFS= read -r zip_url; do
        current=$((current + 1))
        local file_name; file_name=$(basename "$zip_url")

        echo "⬇️ [$current/$total_files] Baixando $file_name (Aguarde...)"
        
        # Wget é mais verboso e amigável para logs (mostra % e tamanho)
        # --progress=dot:giga imprime um ponto a cada monte de bytes, bom para log não ficar gigante
        # mas para o dashboard, talvez --progress=bar:force seja melhor se o log capturar stderr
        
        if wget -nv --show-progress -O "$DEST_DIR/$file_name" "$zip_url"; then
             echo "✅ Download de $file_name concluído."
        else 
             printf "${RED}Error: Failed to download %s${NC}\n" "$file_name" >&2
             # Tenta curl como fallback
             if curl -L -k -A "$USER_AGENT" -o "$DEST_DIR/$file_name" "$zip_url"; then
                 echo "✅ Download de $file_name concluído (via Curl)."
             else
                 continue
             fi
        fi

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