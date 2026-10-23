// LP の文言のうち、表や一覧として繰り返し使うデータ。
// 文言の正本はこのリポジトリ（2026-10-08 に LP を正とした）。元は blog-vault の
// 10_Content/Pages/間取り図AI内覧動画.md（WordPress 固定ページ 2055）と 31_Project/間取り図動画/60_Sales/出品文案.md。
// 価格の書き方は景品表示法の決まり（blog-vault 31_Project/間取り図動画/01_マネタイズ設計.md §1.7）に従う。
// 「今だけ」「期間限定」「先着」「お得」「○% オフ」「通常 ○円」は書かない。
// 値上げ・改定の予告と「オープニング価格」も書かない（2026-10-08、ココナラが期限を書かない値上げ予告を景品表示法違反として出品を取り下げたため）。

import form from '../../public/api/form.json';

export const SITE = {
  url: 'https://madori.macmariya.com/',
  title: '室内写真から作るAI内覧動画｜不動産会社・工務店さま向け',
  description:
    'お手持ちの室内写真と間取り図から、部屋の中を歩いて見て回るAI内覧動画を制作します。不動産会社・工務店向け。料金は写真の枚数で決まり、6枚まで8,000円、初稿は3営業日。石川県から全国に対応します。',
  contactUrl: '/contact/',
  mail: 'info@macmariya.com',
  coconalaUrl: 'https://coconala.com/services/4440820',
  businessUrl: 'https://www.macmariya.com/business',
  policyUrl: 'https://www.macmariya.com/policy',
  operator: 'マクマリ',
};

// 料金の計算の正本は public/api/form.json の pricing（PHP の概算と共用）
export const FORM = form;
export const PRICING = form.pricing;
/** 写真の枚数から料金（税込）を出す。上限を超える・未定は null（要見積） */
export function estimate(photos: number | null): number | null {
  if (photos === null || photos < 1 || photos > PRICING.max_photos) return null;
  return PRICING.base_price + Math.max(0, photos - PRICING.base_photos) * PRICING.per_extra_photo;
}
const yen = (n: number | null) => (n === null ? '' : `${n.toLocaleString('ja-JP')}円`);

export type Video = {
  id: string;
  title: string;
  label: string;
  meta: string;
  poster: string;
};

// 制作例は move 版（部屋ごとに歩く。2026-10-19 ユーザー決定で still と walk の制作例は外した）。
// MAIN_VIDEO は実在の住宅（戸建ての 1 階）、SIZE_VIDEOS は架空 1K・1LDK・2LDK。どれも音入り（2026-10-20 に音入りを標準にした）。
// YouTube は 4 本とも限定公開（2026-10-20 にユーザーがアップロード）。ポスター画像は scripts/make_media.sh
export const MAIN_VIDEO: Video = {
  id: 'SMeuQ7Phhx4',
  title: '室内写真と間取り図からAIで作る内覧動画（戸建て1階・サンプル）',
  label: '戸建ての1階・写真15枚',
  meta: '約139秒・LDK18帖＋和室・洋室2室＋水回り',
  poster: '/media/yt-move-house.jpg',
};

export type Slide = { src: string; cap: string };
export type SizeExample = Video & { slides: Slide[] };

// 架空物件の制作例は、お送りいただく図面と写真（スライド）と完成した動画を並べて見せる（2026-10-20 ユーザー決定）。
// スライドの画像は scripts/make_flow_media.py。部屋名は blog-vault の 10_Input/{slug}/写真対応表.md の「写っている部屋」
const slides = (key: string, rooms: string[]): Slide[] => [
  { src: `/media/flow-${key}-plan.jpg`, cap: '間取り図' },
  ...rooms.map((r, i) => ({ src: `/media/flow-${key}-${String(i + 1).padStart(2, '0')}.jpg`, cap: `写真${i + 1}　${r}` })),
];

export const SIZE_VIDEOS: SizeExample[] = [
  {
    id: 'HYDOhBbbn-A', title: '1K・写真6枚の制作例（架空物件）', label: '1K・写真6枚', meta: '約48秒', poster: '/media/yt-move-1k.jpg',
    slides: slides('1k', ['玄関', 'キッチン', '洋室', '浴室', '洗面室', 'トイレ']),
  },
  {
    id: '3kdyW_dPL_0', title: '1LDK・写真8枚の制作例（架空物件）', label: '1LDK・写真8枚', meta: '約75秒', poster: '/media/yt-move-1ldk.jpg',
    slides: slides('1ldk', ['玄関', 'LDK（キッチン側）', 'LDK（窓側）', 'キッチン', '洋室', '浴室', '洗面室', 'トイレ']),
  },
  {
    id: 'zCOAgLBrFic', title: '2LDK・写真10枚の制作例（架空物件）', label: '2LDK・写真10枚', meta: '約73秒', poster: '/media/yt-move-2ldk.jpg',
    slides: slides('2ldk', ['玄関', 'ホール', 'LDK（キッチン側）', 'LDK（窓側）', 'キッチン', '洋室1', '洋室2', '浴室', '洗面室', 'トイレ']),
  },
];

export const PRICES = [
  { photos: `${PRICING.base_photos}枚まで`, price: yen(estimate(PRICING.base_photos)), layout: '1R・1K' },
  { photos: '8枚', price: yen(estimate(8)), layout: '1LDK' },
  { photos: '10枚', price: yen(estimate(10)), layout: '2LDK' },
  { photos: '15枚', price: yen(estimate(15)), layout: '3LDK' },
  { photos: `${PRICING.base_photos + 1}枚目から`, price: `1枚ごとに +${PRICING.per_extra_photo.toLocaleString('ja-JP')}円`, layout: `上限${PRICING.max_photos}枚。それ以上はご相談ください` },
];

export const OPTIONS = [
  // 追加の修正は 2 回まで（2026-10-23 ユーザー決定。Ryzen は 1 台で、1 回に撮り直し 3 区間まで受けるため）
  { name: '追加の修正（1回ごと・2回まで。撮り直しは3区間まで）', price: '2,000円' },
  { name: '音の変更（1回ごと。曲調の指定か、候補3つからの選び直し）', price: '2,000円' },
  { name: '写真に写り込んだ物・私物の消去', price: '枚数と内容を伺ってお見積りします' },
];

export const DELIVERABLES = [
  { k: '形式', v: 'mp4（16:9）' },
  { k: '解像度', v: 'フルHD（1920×1080）・24fps' },
  { k: '長さ', v: '部屋の数で決まります（写真7枚の1DKで約55秒、写真15枚の戸建て1階で約139秒）' },
  { k: '音声', v: 'BGM・環境音・効果音入り（AIで作ったオリジナル音源）。音なしの版も一緒にお渡しします。ナレーションは付けません' },
  { k: '表示', v: '画面右上に「AI生成映像」を常時表示し、最後に「実物と細部が異なります」の注記を入れます' },
];

export const FLOW = [
  { title: '資料をお送りください', body: '室内写真と間取り図の画像をお送りください。作れるかどうかと、写真の枚数での金額をお知らせします。' },
  { title: '順路と文言のご確認', body: '歩く順路・部屋の順番・部屋名・冒頭と最後のカードの文言を、確認用の映像（間取り図から起こした簡易版）でご確認いただきます。' },
  { title: '初稿のお届け', body: '資料を受け取ってから3営業日以内に、音入りの初稿をお届けします。映像とあわせて音もご確認ください。' },
  { title: '修正1回で納品', body: '修正1回（1営業日）で納品です。' },
];

export const FAQ = [
  {
    q: '寸法の入っていない間取り図でも作れますか',
    a: '専有面積が書かれていれば、作れることがあります（販売図面から作った例があります）。面積と、扉などの標準的な大きさから縮尺を起こします。寸法も面積も読み取れない図面では、お受けできません。',
  },
  {
    q: 'ポータルサイトに載せている写真でも作れますか',
    a: '作れます。掲載用に小さくした写真から作れることを確かめています。撮影した元の写真があれば、細部がよりくっきりするので、そちらをお送りください。',
  },
  {
    q: '写真が無い部屋はどうなりますか',
    a: '写真が無い部屋は動画に入れられません。入れたい部屋があれば、その部屋の写真を追加でお送りください。写真の無い部屋を通らないと順路がつながらない場合は、お受けできないことがあります。',
  },
  {
    q: '音なしにできますか',
    a: '音入りと音なしの2本をお渡しします。音を外す・音量を変えるだけなら、無料の修正で承ります。曲調を変えるなど別の音に作り直す場合は、オプション「音の変更」になります。',
  },
  {
    q: '石川県外からも依頼できますか',
    a: 'できます。資料のやり取りと確認はメールとオンラインで行います。石川県近郊の事業者さまは、ご訪問してのお打ち合わせも承ります。',
  },
];
