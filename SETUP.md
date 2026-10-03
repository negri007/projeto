# Rodar o Echo em outra máquina

Guia para subir o projeto do zero: a rede social entre pessoas e o **Aulas**
(turmas). Testado em Windows (XAMPP); Linux/macOS no fim.

---

## 1. Pré-requisitos (instalar uma vez)

| Ferramenta | Pra quê | Windows |
|---|---|---|
| **PHP 8.2** + **MySQL/MariaDB** | back-end + banco | [XAMPP](https://www.apachefriends.org) (já traz os dois) |
| **ffmpeg** (opcional) | converter HEIC/TIFF/BMP/AVIF para JPG no upload de foto (sem ele, esses formatos são recusados) | `winget install Gyan.FFmpeg` — depois ponha o caminho em `ffmpeg_bin` (seção 4) |
| **Git** | clonar o repo | [git-scm.com](https://git-scm.com) |

---

## 2. Clonar o projeto

```bash
git clone https://github.com/negri007/projeto.git
cd projeto
git checkout echo-enxuto
```

---

## 3. Banco de dados

Com o MySQL do XAMPP rodando (painel do XAMPP → Start no MySQL):

```bash
# cria o banco "banco" e importa o schema
"C:/xampp/mysql/bin/mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS banco CHARACTER SET utf8mb4;"
"C:/xampp/mysql/bin/mysql.exe" -u root --default-character-set=utf8mb4 banco < banco.sql
```

O `banco.sql` cria todas as tabelas e pode rodar de novo por cima sem erro.
Ele faz `USE banco`: para testar numa base com outro nome, troque o nome no
`CREATE DATABASE` e no `USE` numa cópia do arquivo.

Usuário `root` sem senha é o padrão do XAMPP e é o que o sistema usa **só em
ambiente local** quando não existe `api/auth/db_config.php`. Com senha, ou em
servidor publicado, crie o `db_config.php` (seção 4).

Para ter dados de exemplo (20 pessoas com posts, amizades e curtidas):

```bash
"C:/xampp/php/php.exe" api/seed/seed_completo.php
```

Contas de teste do seed: e-mails `@echo.local`, senha `senha123`. Admin (quem
aprova professor e vê os reportes de material) é marcado à mão:
`UPDATE users SET is_admin = 1 WHERE email = '...';`

---

## 4. Arquivos de configuração (chaves)

Os arquivos com chaves **não vão pro git**. Copie cada `.example` e preencha
(ou deixe sem — o que estiver sem chave só desliga aquele recurso):

```bash
cp api/ai/ai_config.example.php        api/ai/ai_config.php
cp api/posts/posts_config.example.php  api/posts/posts_config.php   # opcional
cp api/auth/mail_config.example.php    api/auth/mail_config.php
cp api/auth/google_config.example.php  api/auth/google_config.php
cp api/auth/db_config.example.php      api/auth/db_config.php       # opcional no local
```

O que cada um libera:
- **ai_config.php** — chave da API do Claude, usada pelo **Aulas** (resumo de
  material e quiz) e pela triagem opcional do pedido de professor. Sem ela, o
  resto do app funciona; resumo e quiz avisam que a IA não está configurada.
  `pexels_api_key` é opcional (só o seed usa, para fotos de exemplo).
- **posts_config.php** — `ffmpeg_bin`, o caminho do `ffmpeg` se ele não
  estiver no PATH.
- **mail_config.php** — SMTP pra recuperação de senha por e-mail.
- **google_config.php** — login com Google (ver seção 6).
- **db_config.php** — host, banco, usuário e senha do MySQL. Opcional na
  máquina local (sem ele vale root sem senha); **obrigatório** em servidor:
  lá, sem ele, o banco não conecta e o motivo vai pro log do PHP.

---

## 5. Subir o servidor (Apache do XAMPP)

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

> **Por que Apache e não `php -S`:** o servidor embutido do PHP atende um
> pedido por vez, e a conexão de notificações (SSE) segura cada aba aberta
> por alguns segundos — com mais de uma aba, tudo enfileira. O `php -S`
> serve pra um teste rápido (`"C:/xampp/php/php.exe" -S 127.0.0.1:8080 -t .`
> com o Apache parado). Também é só no Apache que os `.htaccess` (pastas
> negadas, cabeçalhos de segurança) valem.

---

## 6. Login com Google (opcional)

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

## Linux / macOS (equivalências)

- PHP/MySQL: `apt install php mariadb-server` / `brew install php mariadb`.
- ffmpeg (opcional): `apt install ffmpeg` / `brew install ffmpeg`.
- Importar banco: `mysql -u root --default-character-set=utf8mb4 banco < banco.sql`.
- Servidor: Apache com um `VirtualHost` na porta 8080 apontando para a pasta
  do projeto (`AllowOverride All`), ou `php -S 127.0.0.1:8080 -t .` para
  teste rápido (ver seção 5).
- Caminhos do `mysql`/`php` sem o prefixo `C:/xampp/...`.

> O projeto completo, com a rede de IAs, o comércio e o motor de vídeo, está
> preservado na branch `backup/echo-completo-2026-10-01`.
