// LP の文言のうち、表や一覧として繰り返し使うデータ。
// 文言の正本はこのリポジトリ（2026-10-08 に LP を正とした）。元は blog-vault の
// 10_Content/Pages/間取り図AI内覧動画.md（WordPress 固定ページ 2055）と 31_Project/間取り図動画/60_Sales/出品文案.md。
// 価格の書き方は景品表示法の決まり（blog-vault 31_Project/間取り図動画/01_マネタイズ設計.md §1.7）に従う。
// 「今だけ」「期間限定」「先着」「お得」「○% オフ」「通常 ○円」は書かない。

export const SITE = {
  url: 'https://madori.macmariya.com/',
  title: '室内写真から作るAI内覧動画｜不動産会社・工務店さま向け',
  description:
    'お手持ちの室内写真から、写真1枚ごとにカメラがゆっくり寄る・振る・前に進むAI内覧動画を制作します。不動産会社・工務店向け。料金は写真の枚数で決まり、6枚まで8,000円（オープニング価格）、初稿は3営業日。石川県から全国に対応します。',
  contactUrl: 'https://www.macmariya.com/contact',
  mail: 'info@macmariya.com',
  coconalaUrl: 'https://coconala.com/services/4440820',
  businessUrl: 'https://www.macmariya.com/business',
  operator: 'マクマリ',
};

export const OPENING_PRICE_NOTE =
  'オープニング価格です。実績が増えたら改定します（時期は未定です）。ご注文後に料金が変わることはありません。';

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

export const PRICES = [
  { photos: '6枚まで', price: '8,000円', layout: '1R・1K' },
  { photos: '8枚', price: '10,000円', layout: '1LDK' },
  { photos: '10枚', price: '12,000円', layout: '2LDK' },
  { photos: '15枚', price: '17,000円', layout: '3LDK' },
  { photos: '7枚目から', price: '1枚ごとに +1,000円', layout: '上限20枚。それ以上はご相談ください' },
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
    q: '石川県外からも依頼できますか',
    a: 'できます。資料のやり取りと確認はメールとオンラインで行います。石川県近郊の事業者さまは、ご訪問してのお打ち合わせも承ります。',
  },
];
