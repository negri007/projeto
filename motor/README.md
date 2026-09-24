# Motor de Anúncios — Echo

Gera vídeos de marketing por nicho **sem custo de API** (render local com
Remotion + ffmpeg). Dois formatos:

- **Flash** — 1 cena (~5s) — plano Básico.
- **História** — 3 cenas (~7s) — plano Pro.

Plano completo: [`../docs/plans/motor-anuncios.md`](../docs/plans/motor-anuncios.md).

---

## Rodar em uma máquina nova (setup, uma vez)

Pré-requisitos: **Node 18+** e **ffmpeg** no sistema.

```bash
# 1. dependências (baixa Remotion etc — lê do package.json)
cd motor
npm install

# 2. Chromium que o Remotion usa pra renderizar (baixa sozinho)
npm run ensure-browser
```

Instalar ffmpeg, se ainda não tiver:

- Windows: `winget install Gyan.FFmpeg`
- Linux (Debian/Ubuntu): `sudo apt install ffmpeg`
- macOS: `brew install ffmpeg`

Requisito de hardware: renderizar usa Chrome headless, que pede **~2 GB de
RAM**. Máquina própria, VPS de 2 GB+, ou Oracle Cloud Free Tier resolvem.
Hospedagem PHP compartilhada **não** roda isto — nesse caso, rode o motor num
worker separado (ver a seção 5 do plano).

---

## Ver os modelos (studio interativo)

```bash
npm run studio
```

Abre no navegador. Composições registradas: `Flash`, `FlashV2` (mesma comida,
outro layout — prova que não repete), `Historia`, e um Flash por nicho
(`FlashModa`, `FlashJoia`, `FlashTech`, `FlashBeleza`, `FlashFitness`).

## Renderizar um MP4

```bash
npm run flash       # -> out/flash.mp4
npm run historia    # -> out/historia.mp4
```

Ou qualquer composição, com dados próprios via `--props`:

```bash
npx remotion render src/index.js Flash out/x.mp4 --props='{"nicho":"moda","chamada":["NOVA","COLECAO"],"preco":"R$ 189","marca":"ATELIE","foto":"moda.jpg"}'
```

---

## Como é montado

- `src/presets.js` — o **estilo** de cada nicho (cor, fonte, filtro, clima da
  trilha). É aqui que se ajusta a identidade visual.
- `src/Flash.jsx` — o formato de 1 cena. Recebe os dados da loja como props.
- `src/Historia.jsx` — o formato de 3 cenas (Hero → Detalhe → Fecho).
- `src/Root.jsx` — registra as composições e os dados de demonstração.
- `public/` — imagens de exemplo. Em produção, a foto vem da loja.

O servidor do Echo injeta `{nicho, foto, textos, preço, cor}` como props no
render — os `defaultProps` do `Root.jsx` são só o exemplo que abre no studio.

## O que NÃO vai pro git

`node_modules/`, `out/`, Chromium e `*.mp4` são reconstruídos/gerados em cada
máquina (ver `.gitignore`). Commita-se só código + config + assets pequenos.
