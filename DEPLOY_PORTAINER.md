# 📘 Guia Passo a Passo: Deploy do CNPJ no Portainer (Iniciante)

Este guia foi feito para quem tem **zero conhecimento** prévio. Siga exatamente cada passo.

**Objetivo:** Colocar o sistema no ar em `https://cnpjrfb.agenciataruga.com`.
**Pasta no Servidor:** `/root/cnpj`

---

## 🚀 Passo 1: Enviando os Arquivos (FileZilla / SFTP)

1.  Abra seu programa de FTP (ex: FileZilla) e conecte no seu servidor.
2.  Navegue até a pasta `/root/`.
3.  Crie uma nova pasta chamada `cnpj` dentro de `/root/`.
4.  **O que subir?**
    Arraste **TODOS** os arquivos e pastas deste projeto (que estão no seu computador) para dentro da pasta `/root/cnpj` no servidor.
    
    *Certifique-se de que estruturas como `www`, `modelo_banco`, `php-custom.ini`, `Dockerfile` e `portainer-stack.yml` subiram corretamente.*

---

## 🐳 Passo 2: Criando a "Imagem" do Sistema (Terminal)

O Portainer (versão Web) não consegue "criar" o sistema do zero apenas lendo os arquivos, ele precisa que a "Imagem Docker" já exista. Vamos criar essa imagem com um comando simples.

1.  Acesse seu servidor via Terminal (SSH/Putty).
2.  Entre na pasta que você criou:
    ```bash
    cd /root/cnpj
    ```
3.  Rode este comando para criar a imagem (pode demorar uns 2 minutos):
    ```bash
    docker build -t cnpj-app:latest -f Dockerfile.slim .
    ```
    *(Não esqueça do ponto final `.` no comando!)*

    **Se aparecer "Successfully tagged cnpj-app:latest" no final, deu certo!** ✅

---

## 🚢 Passo 3: Configurando no Portainer (Web)

1.  Abra o seu Portainer no navegador.
2.  No menu esquerdo, clique em **Stacks**.
3.  Clique no botão **+ Add stack** (canto direito superior).
4.  Preencha assim:
    *   **Name:** `cnpj_stack`
    *   **Build method:** Escolha a opção **Web editor** (ícone de lápis).
5.  Na caixa de texto grande (Web editor), **apague tudo** e cole o conteúdo EXATO do arquivo `portainer-stack.yml` que está no seu projeto.
    *(Já configurei ele com o domínio `cnpjrfb.agenciataruga.com` e a conexão com seu banco Orion).*
6.  Role a tela para baixo e clique no botão azul **Deploy the stack**.

---

## ✅ Passo 4: Verificando se Funcionou

1.  Espere uns segundos. Se a página recarregar e mostrar a stack `cnpj_stack` na lista, parabéns!
2.  Tente acessar no navegador: **https://cnpjrfb.agenciataruga.com**
    *(Pode demorar uns minutinhos para o Traefik gerar o certificado de segurança).*

---

## 📦 Passo Final: Importando os Dados

Agora que o site está no ar, precisamos preencher o banco de dados.

1.  No Portainer, clique em **Containers**.
2.  Ache o container `cnpj_app` e clique no ícone **>_ Console** (ou "Exec Console").
3.  Clique em **Connect** (deixe as opções padrão `/bin/bash` e `root`).
4.  Vai abrir uma tela preta de terminal. Digite (uma linha por vez):

    **A. Baixar os arquivos da Receita:**
    ```bash
    cd /var/www/html/cargabd/download && ./download_files.sh
    ```
    *(Isso vai demorar bastante. Vá tomar um café ☕)*

    **B. Extrair os arquivos:**
    ```bash
    ./unzip_files.sh
    ```

    **C. Importar para o Banco:**
    ```bash
    php /var/www/html/cargabd/index.php
    ```

**Pronto! Seu sistema está 100% operacional.** 🚀
