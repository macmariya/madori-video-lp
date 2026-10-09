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
    'お手持ちの室内写真から、写真1枚ごとにカメラがゆっくり寄る・振る・前に進むAI内覧動画を制作します。不動産会社・工務店向け。料金は写真の枚数で決まり、6枚まで8,000円、初稿は3営業日。石川県から全国に対応します。',
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

export const MAIN_VIDEO: Video = {
  id: '48xLnm3BciY',
  title: '室内写真からAIで作る内覧動画（サンプル）',
  label: '戸建ての1階・写真15枚',
  meta: '約74秒・LDK18帖＋和室・洋室2室＋水回り',
  poster: '/media/yt-house.jpg',
};

export const SIZE_VIDEOS: Video[] = [
  { id: 'CZsIXQm6Neg', title: '1K・写真6枚の制作例（架空物件）', label: '1K・写真6枚', meta: '約36秒', poster: '/media/yt-1k.jpg' },
  { id: 'bkJoo3bAsJ0', title: '1LDK・写真8枚の制作例（架空物件）', label: '1LDK・写真8枚', meta: '約44秒', poster: '/media/yt-1ldk.jpg' },
  { id: 'EgjMTAC_zOI', title: '2LDK・写真10枚の制作例（架空物件）', label: '2LDK・写真10枚', meta: '約53秒', poster: '/media/yt-2ldk.jpg' },
];

// 玄関から歩いて回る版（walk）。料金は載せず、お見積りにする（2026-10-10 ユーザー決定。実績を積んでから価格を決める）
export const WALK_VIDEO: Video = {
  id: '2WTRCgJ0Acw',
  title: '玄関から歩いて回る動画の制作例（1K・架空物件）',
  label: '1K・玄関から歩いて回る動画',
  meta: '約67秒・写真6枚と寸法の読める間取り図から',
  poster: '/media/yt-walk-1k.jpg',
};

export const PRICES = [
  { photos: `${PRICING.base_photos}枚まで`, price: yen(estimate(PRICING.base_photos)), layout: '1R・1K' },
  { photos: '8枚', price: yen(estimate(8)), layout: '1LDK' },
  { photos: '10枚', price: yen(estimate(10)), layout: '2LDK' },
  { photos: '15枚', price: yen(estimate(15)), layout: '3LDK' },
  { photos: `${PRICING.base_photos + 1}枚目から`, price: `1枚ごとに +${PRICING.per_extra_photo.toLocaleString('ja-JP')}円`, layout: `上限${PRICING.max_photos}枚。それ以上はご相談ください` },
];

export const OPTIONS = [
  { name: '追加の修正（1回ごと。撮り直しは3カットまで）', price: '2,000円' },
  { name: '写真に写り込んだ物・私物の消去', price: '枚数と内容を伺ってお見積りします' },
];

export const DELIVERABLES = [
  { k: '形式', v: 'mp4（16:9）' },
  { k: '解像度', v: 'フルHD（1920×1080）・24fps' },
  { k: '長さ', v: '写真の枚数で決まります（6枚で約35秒、15枚で約74秒）' },
  { k: '音声', v: 'なし（BGM・ナレーションは付けません）' },
  { k: '表示', v: '画面右上に「AI生成映像」を常時表示し、最後に「実物と細部が異なります」の注記を入れます' },
];

export const FLOW = [
  { title: '資料をお送りください', body: '室内写真と間取り図の画像をお送りください。写真の枚数でお見積りします。' },
  { title: '動きと文言のご確認', body: '写真の順番・動き・部屋名・冒頭と最後のカードの文言を、確認用の映像（写真を動かしただけの簡易版）でご確認いただきます。' },
  { title: '初稿のお届け', body: '資料を受け取ってから3営業日以内に初稿をお届けします。' },
  { title: '修正1回で納品', body: '修正1回（1営業日）で納品です。' },
];

export const FAQ = [
  {
    q: '寸法の入っていない間取り図でも作れますか',
    a: '作れます。その場合はすべての写真を、カメラの位置を動かさずに寄る・振る映像にします。料金は同じです。',
  },
  {
    q: 'ポータルサイトに載せている写真でも作れますか',
    a: '作れます。ただし掲載用に小さくした写真は、映像の最初と最後がややぼやけます。撮影した元の写真があれば、そちらをお送りください。',
  },
  {
    q: '写真が無い部屋はどうなりますか',
    a: '写真1枚ごとに映像を作るので、写真が無い部屋は動画に入りません。入れたい部屋があれば、その部屋の写真を追加でお送りください。',
  },
  {
    q: '玄関から部屋を歩いて回る動画も作れますか',
    a: '作れます。寸法の読める間取り図から部屋の立体を起こし、玄関から順に歩いて回る一続きの映像にします（制作例の欄に1Kの例があります）。写真に写っていない眺めをAIが描くため、写真1枚ごとの映像より細部が写真と違いやすく、確認の回数も増えます。料金は間取りと動画の長さを伺い、お見積りします。',
  },
  {
    q: '石川県外からも依頼できますか',
    a: 'できます。資料のやり取りと確認はメールとオンラインで行います。石川県近郊の事業者さまは、ご訪問してのお打ち合わせも承ります。',
  },
];
