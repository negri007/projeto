import {AbsoluteFill, Img, staticFile} from 'remotion';

// FOTO — como a foto do produto ocupa o quadro. Dois modos:
//   'preencher' (padrao): enche a tela (cover). Pode cortar as bordas — o
//        `foco {x,y,zoom}` decide QUAL parte aparece (arrastar/zoom no front).
//   'inteira': mostra o produto TODO (contain), com uma versao desfocada da
//        propria foto preenchendo o fundo (moldura tipo Instagram). Nunca corta.
//
// foco: {x,y} em 0..1 (0.5,0.5 = centro), zoom >= 1. So vale no 'preencher'.
export const Foto = ({src, filtro = '', ajuste = 'preencher', foco}) => {
  const f = foco || {};
  const x = Math.min(1, Math.max(0, f.x == null ? 0.5 : f.x)) * 100;
  const y = Math.min(1, Math.max(0, f.y == null ? 0.5 : f.y)) * 100;
  const zoom = Math.min(3, Math.max(1, f.zoom == null ? 1 : f.zoom));

  if (ajuste === 'inteira') {
    return (
      <AbsoluteFill>
        <AbsoluteFill>
          <Img src={staticFile(src)} style={{width: '100%', height: '100%', objectFit: 'cover',
            filter: `blur(30px) brightness(.5) ${filtro}`, transform: 'scale(1.12)'}} />
        </AbsoluteFill>
        <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center'}}>
          <Img src={staticFile(src)} style={{maxWidth: '100%', maxHeight: '100%', width: 'auto', height: 'auto',
            objectFit: 'contain', filter: filtro, transform: `scale(${zoom})`}} />
        </AbsoluteFill>
      </AbsoluteFill>
    );
  }

  return (
    <AbsoluteFill>
      <Img src={staticFile(src)} style={{width: '100%', height: '100%', objectFit: 'cover',
        objectPosition: `${x}% ${y}%`, filter: filtro, transform: `scale(${zoom})`}} />
    </AbsoluteFill>
  );
};
