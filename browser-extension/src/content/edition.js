// 管理者版か一般版かを表すビルド時定数（このファイルの値は管理者版用）。
// 一般版ビルドでは rollup.config.mjs の generalStubs が edition-general.js（true）に差し替えるため、
// `if (!IS_GENERAL_EDITION)` の分岐が畳み込まれ、一般版で到達しない管理者向けのコードが
// tree-shaking で成果物から消える。
// 実行時に判定する値（state など）で分岐させると成果物に残るので、必ずこの定数を使うこと。
export const IS_GENERAL_EDITION = false;
