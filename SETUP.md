# Rodar o Echo em outra máquina

Guia completo pra subir o projeto do zero, incluindo o **motor de anúncios**
(vídeos de marketing). Testado em Windows (XAMPP); Linux/macOS no fim.

---

## 1. Pré-requisitos (instalar uma vez)

| Ferramenta | Pra quê | Windows |
|---|---|---|
| **PHP 8.2** + **MySQL/MariaDB** | back-end + banco | [XAMPP](https://www.apachefriends.org) (já traz os dois) |
| **Node.js 18+** | motor de vídeo (Remotion) | [nodejs.org](https://nodejs.org) — instalador põe no PATH |
| **ffmpeg** | converter HEIC/TIFF no upload (opcional) | `winget install Gyan.FFmpeg` |
| **Git** | clonar o repo | [git-scm.com](https://git-scm.com) |

> O motor precisa de **~2 GB de RAM** livre pra renderizar (Chrome headless).

---

## 2. Clonar o projeto

```bash
git clone https://github.com/negri007/projeto.git
cd projeto
git checkout feature/videos-ia
```

---

## 3. Banco de dados

Com o MySQL do XAMPP rodando (painel do XAMPP → Start no MySQL):

```bash
# cria o banco "banco" e importa o schema
"C:/xampp/mysql/bin/mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS banco CHARACTER SET utf8mb4;"
"C:/xampp/mysql/bin/mysql.exe" -u root --default-character-set=utf8mb4 banco < banco.sql
```

O `banco.sql` cria todas as tabelas (inclusive `videos_gerados` com as colunas
do motor). Usuário `root` sem senha é o padrão do XAMPP — se a sua instalação
tiver senha, ajuste em `api/auth/db.php`.

Contas de teste (se rodar o seed): e-mails `@echo.local`, senha `senha123`.

---

## 4. Arquivos de configuração (chaves)

Os arquivos com chaves **não vão pro git**. Copie cada `.example` e preencha
(ou deixe em branco — o que estiver sem chave só desliga aquele recurso):

```bash
cp api/ai/ai_config.example.php        api/ai/ai_config.php
cp api/video/video_config.example.php  api/video/video_config.php
cp api/auth/mail_config.example.php    api/auth/mail_config.php
cp api/auth/google_config.example.php  api/auth/google_config.php
```

O que cada um libera:
- **ai_config.php** — chave Pexels (fotos/ vídeos de banco grátis) e IA de texto.
- **video_config.php** — Kling (vídeo por IA, pago) e Coverr. **O motor de
  anúncios NÃO precisa de nenhuma chave** — é render local, custo zero.
  - Opcional: `node_bin` (caminho do `node`) e `ffmpeg_bin` (caminho do
    `ffmpeg`) se não estiverem no PATH.
- **mail_config.php** — SMTP pra recuperação de senha por e-mail.
- **google_config.php** — login com Google (ver seção 7).

**O essencial pro app + motor funcionar não precisa de nenhuma chave.**

---

## 5. Motor de anúncios (Remotion)

```bash
cd motor
npm install              # baixa Remotion e dependências
npm run ensure-browser   # baixa o Chromium que o Remotion usa
cd ..
```

Confirme que o `node` responde (o render roda por ele):

```bash
node -v
```

Se der "não reconhecido", feche e abra o terminal (o instalador do Node já pôs
no PATH) e rode de novo.

---

## 6. Subir o servidor

```bash
"C:/xampp/php/php.exe" -S 127.0.0.1:5258 -t .
```

Deixe essa janela aberta e abra no navegador:

```
http://127.0.0.1:5258/index.html
```

Login → aba **Canvas** → **Loja** → **Gerar vídeo de marketing**.

> A aba **Loja** só aparece pra conta que tem loja. Crie uma em **Comércio**.

---

## 7. Login com Google (opcional)

Precisa das credenciais em `api/auth/google_config.php` (Client ID/Secret do
[Google Cloud Console](https://console.cloud.google.com)) e que a **porta bata**
com o `redirect_uri`. No Console, em *Credenciais → seu cliente OAuth → URIs de
redirecionamento autorizados*, cadastre exatamente:

```
http://127.0.0.1:<PORTA>/api/auth/google_callback.php
```

E rode o servidor **nessa mesma porta**, navegando por `127.0.0.1` (não
`localhost`). Se o app estiver em "Teste" no Console, adicione seu e-mail em
*Tela de consentimento → Usuários de teste*.

---

## 8. Onde o motor não roda

O motor precisa de Node + Chromium. **Hospedagem PHP compartilhada** (tipo
Hostinger shared) **não roda** isso. Alternativas com custo zero: a própria
máquina, ou o **Oracle Cloud Free Tier** (VM ARM grátis). Também dá pra separar:
o PHP fica no host barato e um **worker** (sua máquina / VM grátis) faz o render
lendo a fila `videos_gerados`.

---

## Linux / macOS (equivalências)

- PHP/MySQL: `apt install php mariadb-server` / `brew install php mariadb`.
- ffmpeg: `apt install ffmpeg` / `brew install ffmpeg`.
- Node: [nodejs.org](https://nodejs.org) ou `nvm`.
- Importar banco: `mysql -u root banco < banco.sql`.
- Servidor: `php -S 127.0.0.1:5258 -t .`.
- Caminhos do `mysql`/`php` sem o prefixo `C:/xampp/...`.
