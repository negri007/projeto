import {Composition, Audio, staticFile, useVideoConfig, interpolate} from 'remotion';
import {trilha as trilhaPadrao} from './presets';
import {Flash} from './Flash';
import {Historia} from './Historia';
import {Manchete} from './Manchete';
import {Vitrine} from './Vitrine';
import {Ficha} from './Ficha';
import {Luxo} from './Luxo';
import {Glitch} from './Glitch';
import {Editorial} from './Editorial';
import {AntesDepois} from './AntesDepois';
import {Depoimento} from './Depoimento';
import {Combo} from './Combo';
import {Cupom} from './Cupom';
import {Countdown} from './Countdown';

// FORMATOS — o servidor manda `formato` nos props e a dimensao sai daqui,
// sem reescrever template (o layout ancora nas bordas). Ver secao 10 do
// plano em docs/plans/motor-anuncios.md.
const FORMS = {
  quadrado: [1080, 1080],  // 1:1  feed classico
  story:    [1080, 1920],  // 9:16 Story / Reels
  feed:     [1080, 1350],  // 4:5  feed master
  paisagem: [1920, 1080],  // 16:9 YouTube / site
};
const FPS = 30;

// TRILHA embutida no proprio render (Remotion <Audio>) — nao precisa de
// ffmpeg externo, entao o motor roda com so `npm install`. `musica` e o
// nome do arquivo em public/mus (sem extensao); ausente = padrao do nicho;
// string vazia = sem trilha.
const Trilha = ({nome}) => {
  const {durationInFrames: d} = useVideoConfig();
  const vol = (f) => interpolate(f, [0, 12, d - 20, d], [0, 0.78, 0.78, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  return <Audio src={staticFile('mus/' + nome + '.mp3')} volume={vol} />;
};

const comMusica = (Comp) => {
  const Wrapped = (props) => {
    const nome = props.musica === undefined ? trilhaPadrao(props.nicho) : props.musica;
    return (<>
      <Comp {...props} />
      {nome ? <Trilha nome={nome} /> : null}
    </>);
  };
  return Wrapped;
};

// calcula dimensao a partir do formato pedido nos props.
const meta = (baseDur) => ({props}) => {
  const [w, h] = FORMS[props.formato] || FORMS.quadrado;
  return {width: w, height: h, fps: FPS, durationInFrames: baseDur};
};

// [id, Componente, duracaoBase(frames), propsDemo]
const MODELOS = [
  ['Flash', Flash, 150, {nicho: 'comida', chamada: ['SMASH', 'BURGER'], preco: 'R$ 32,90', cta: 'Peca pelo WhatsApp', marca: 'BURGER HOUSE', foto: 'burger.jpg'}],
  ['Historia', Historia, 212, {nicho: 'comida', chamada: ['SMASH', 'BURGER'], sub: 'SUCULENCIA REAL', tags: ['PAO BRIOCHE', 'CARNE 180G', 'CHEDDAR DUPLO'], preco: 'R$ 32,90', cta: 'Peca pelo WhatsApp', marca: 'BURGER HOUSE', fotos: ['hero.jpg', 'detail.jpg']}],
  ['Manchete', Manchete, 168, {nicho: 'comida', selo: 'OFERTA', chamada: ['SMASH', 'BURGER'], preco: 'R$ 32,90', infos: ['PAO BRIOCHE', 'CARNE 180G', 'ENTREGA GRATIS'], cta: 'Peca pelo WhatsApp', marca: 'BURGER HOUSE', foto: 'burger.jpg'}],
  ['Vitrine', Vitrine, 156, {nicho: 'tech', produto: 'FONE PRO', specs: ['40H DE BATERIA', 'CANCELAMENTO DE RUIDO', 'BLUETOOTH 5.3'], preco: 'R$ 349', cta: 'Garanta o seu', marca: 'NOVA AUDIO', foto: 'tech.jpg'}],
  ['Ficha', Ficha, 168, {nicho: 'fitness', chamada: ['FORCA', 'TOTAL'], stats: [{n: 24, unit: 'H', label: 'ABERTO'}, {n: 80, unit: '+', label: 'APARELHOS'}, {n: 12, unit: '', label: 'MODALIDADES'}], preco: 'R$ 99/mes', cta: 'Matricule-se', marca: 'FORCA GYM', foto: 'fitness.jpg'}],
  ['Luxo', Luxo, 168, {nicho: 'joia', chamada: 'Colecao Aurora', sub: 'ouro 18k - diamantes naturais', preco: 'R$ 1.290', cta: 'Agende uma visita', marca: 'AURUM', foto: 'joia.jpg'}],
  ['Glitch', Glitch, 156, {nicho: 'tech', chamada: ['SOM', 'PURO'], specs: ['BLUETOOTH 5.3', '40H BATERIA', 'IPX5'], preco: 'R$ 349', cta: 'Garanta o seu', marca: 'NOVA AUDIO', foto: 'tech.jpg'}],
  ['Editorial', Editorial, 168, {nicho: 'moda', fundo: 'ESTILO', chamada: 'Nova Colecao', sub: 'outono / inverno', preco: 'R$ 189', cta: 'Compre online', marca: 'ATELIE LUNA', foto: 'moda.jpg'}],
  ['AntesDepois', AntesDepois, 168, {nicho: 'beleza', chamada: 'RESULTADO REAL', fotoAntes: 'spa.jpg', fotoDepois: 'spa.jpg', preco: 'R$ 180', cta: 'Agende sua sessao', marca: 'SERENA ESTETICA'}],
  ['Depoimento', Depoimento, 168, {nicho: 'comida', texto: 'Melhor hamburguer que ja comi na cidade. Chega quentinho e o atendimento e impecavel!', cliente: 'Marina Alves', estrelas: 5, foto: 'burger.jpg', marca: 'BURGER HOUSE', cta: 'Peca o seu'}],
  ['Combo', Combo, 168, {nicho: 'comida', titulo: 'CARDAPIO', itens: [{foto: 'burger.jpg', nome: 'Smash Classico', preco: 'R$ 28'}, {foto: 'comida.jpg', nome: 'Duplo Bacon', preco: 'R$ 34'}, {foto: 'detail.jpg', nome: 'Cheddar Melt', preco: 'R$ 32'}, {foto: 'hero.jpg', nome: 'Combo Familia', preco: 'R$ 89'}], cta: 'Peca pelo WhatsApp', marca: 'BURGER HOUSE'}],
  ['Cupom', Cupom, 156, {nicho: 'comida', desconto: '20% OFF', codigo: 'ECHO20', validade: 'valido ate domingo', chamada: 'CUPOM DE DESCONTO', cta: 'Use no checkout', marca: 'BURGER HOUSE', foto: 'burger.jpg'}],
  ['Countdown', Countdown, 168, {nicho: 'fitness', selo: 'SO HOJE', chamada: ['ULTIMAS', 'VAGAS'], inicio: {h: 5, m: 42, s: 18}, preco: 'R$ 99/mes', cta: 'Garanta a sua', marca: 'FORCA GYM', foto: 'fitness.jpg'}],
];

// pre-embrulha cada modelo com a trilha (referencia estavel).
const REG = MODELOS.map(([id, Comp, dur, props]) => [id, comMusica(Comp), dur, props]);

export const RemotionRoot = () => (
  <>
    {REG.map(([id, Comp, dur, props]) => (
      <Composition key={id} id={id} component={Comp} calculateMetadata={meta(dur)}
        durationInFrames={dur} fps={FPS} width={1080} height={1080} defaultProps={props} />
    ))}
  </>
);
