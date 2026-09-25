# Rodar o Echo em outra máquina

Guia completo pra subir o projeto do zero, incluindo o **motor de anúncios**
(vídeos de marketing). Testado em Windows (XAMPP); Linux/macOS no fim.

---

## 1. Pré-requisitos (instalar uma vez)

| Ferramenta | Pra quê | Windows |
|---|---|---|
| **PHP 8.2** + **MySQL/MariaDB** | back-end + banco | [XAMPP](https://www.apachefriends.org) (já traz os dois) |
| **Node.js 18+** | motor de vídeo (Remotion) | [nodejs.org](https://nodejs.org) — instalador põe no PATH |
| **ffmpeg** | converter HEIC/TIFF/BMP/AVIF para JPG no upload (sem ele, esses formatos são recusados) | `winget install Gyan.FFmpeg` — depois ponha o caminho em `ffmpeg_bin` (seção 4) |
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
do motor). As credenciais do banco ficam em `api/auth/db_config.php` (seção 4). Sem
esse arquivo, **só em ambiente local** o app usa o padrão do XAMPP (`root`
sem senha); fora do local ele recusa conectar e deixa o motivo no log.

Contas de teste (se rodar o seed): e-mails `@echo.local`, senha `senha123`.

---

## 4. Arquivos de configuração (chaves)

Os arquivos com chaves **não vão pro git**. Copie cada `.example` e preencha
(ou deixe em branco — o que estiver sem chave só desliga aquele recurso):

```bash
cp api/auth/db_config.example.php      api/auth/db_config.php
cp api/ai/ai_config.example.php        api/ai/ai_config.php
cp api/video/video_config.example.php  api/video/video_config.php
cp api/auth/mail_config.example.php    api/auth/mail_config.php
cp api/auth/google_config.example.php  api/auth/google_config.php
```

O que cada um libera:
- **db_config.php** — host, banco, usuário e senha do MySQL. Em local pode
  faltar (usa `root` sem senha); **em servidor é obrigatório**. Para forçar o
  modo servidor numa máquina local, defina a variável de ambiente `ECHO_ENV`
  com um valor diferente de `local`.
- **ai_config.php** — chave Pexels (fotos/ vídeos de banco grátis) e IA de texto.
- **video_config.php** — Kling (vídeo por IA, pago) e Coverr. **O motor de
  anúncios NÃO precisa de nenhuma chave** — é render local, custo zero.
  - Opcional: `node_bin` (caminho do `node`) e `ffmpeg_bin` (caminho do
    `ffmpeg`) se não estiverem no PATH.
  - `max_renders` (padrão 1): quantos vídeos do motor renderizam ao mesmo
    tempo — cada um abre um Chrome (~1-2 GB de RAM). O excedente espera na
    fila e sai sozinho.
  - `render_timeout_s` (padrão 480 = 8 min): tempo máximo de um render; o
    motor encerra o Chrome e marca erro. Vídeo parado em "gerando" por mais
    de 10 min também vira erro. Para agendar essa limpeza (opcional — ela
    já roda quando alguém abre a tela): `php api/video/limpar_travados.php`.
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

## 6. Subir o servidor (Apache do XAMPP)

O app roda no **Apache do XAMPP**, na porta **8080**:

```
http://127.0.0.1:8080/index.html
```

**Para subir:** abra o painel do XAMPP e clique em **Start** no **Apache** e no
**MySQL**. Pronto — não precisa deixar terminal aberto.

**Primeira vez numa máquina nova:** o site da porta 8080 é configurado no
XAMPP, não no repositório. Acrescente ao fim de
`C:/xampp/apache/conf/extra/httpd-vhosts.conf` (troque o caminho pelo da sua
pasta do projeto) e dê Stop/Start no Apache:

```apache
Listen 8080
<VirtualHost *:8080>
    DocumentRoot "C:/caminho/do/projeto"
    DirectoryIndex index.html index.php
    <Directory "C:/caminho/do/projeto">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Confira também, no `C:/xampp/php/php.ini`, que a linha `extension=gd` está
**sem** o `;` na frente (o upload de fotos do motor precisa do GD) — depois de
mexer no `php.ini`, Stop/Start no Apache.

> **Por que Apache e não `php -S`:** o servidor embutido do PHP atende um
> pedido por vez e manda o vídeo inteiro de uma vez (sem `Range`). Com a
> conexão de notificações aberta, o vídeo do feed levava ~30 s pra começar;
> no Apache começa em menos de 1 s. O `php -S` ainda serve pra um teste
> rápido (`"C:/xampp/php/php.exe" -S 127.0.0.1:8080 -t .` com o Apache
> parado), mas fica lento com vídeo.

> **Motor sob o Apache:** o render roda num `php.exe` separado, disparado em
> segundo plano. O caminho é descoberto sozinho (o `php.exe` ao lado do
> `php.ini` carregado); se não achar, informe `php_bin` em
> `api/video/video_config.php`. Idem `node_bin` se o `node` não estiver no
> PATH de quem iniciou o Apache.

Login → aba **Canvas** → **Loja** → **Gerar vídeo de marketing**. Não precisa
esperar no modal: quando o vídeo fica pronto ele aparece em **Comércio → Meus
vídeos**, de onde dá pra publicar na loja ou baixar.

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

> **Atenção à porta:** a porta do `redirect_uri` precisa ser **a mesma porta
> que serve o app**. Com o Apache do XAMPP isso é a **8080**, então o
> `redirect_uri` em `api/auth/google_config.php` e o URI cadastrado no
> Console viram `http://127.0.0.1:8080/api/auth/google_callback.php`. Se o
> `google_config.php` ainda aponta para outra porta (ex.: 8123 ou 5258), o
> Google devolve `redirect_uri_mismatch` e o login não completa.

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
- Servidor: Apache com um `VirtualHost` na porta 8080 apontando para a pasta
  do projeto (`AllowOverride All`), ou `php -S 127.0.0.1:8080 -t .` para
  teste rápido (lento com vídeo, ver seção 6).
- Caminhos do `mysql`/`php` sem o prefixo `C:/xampp/...`.
