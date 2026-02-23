---
name: clean-code-php
description: "Review and refactor PHP code with Clean Code principles for safer incremental improvements in Laravel/PHP projects."
---

# Clean Code PHP

## Overview

PHP コードを「読みやすい・変更しやすい・壊れにくい」状態に近づける。  
`piotrplenik/clean-code-php` の構成を基準に、実装とテストに落とし込める具体的な改善案を提示して実行する。

## 基本方針

- 一般論ではなく、対象ファイルの実コードを根拠に判断する。
- 一度に全体改修しない。挙動を守る最小差分を優先する。
- 異常系は握り潰さず、追跡可能な失敗として扱う。
- 暗黙フォールバックや NO-OP を追加しない。

## 実行手順

1. 対象と目的を固定する
- 対象のディレクトリ/ファイルを特定する。
- 目的を 1 つに絞る（例: 可読性改善、分岐削減、副作用除去）。

2. まず現状を評価する
- `references/clean-code-php-principles.md` のチェックリストを使い、該当項目を抽出する。
- 変数名、関数引数、条件分岐、型分岐、クラス責務、重複の順で確認する。

3. 改善案を最小単位で設計する
- 1 変更 = 1 意図で分割する。
- 既存 API/戻り値/例外仕様を壊す変更は避ける。
- 先に早期 return と責務分離でネストを減らす。

4. 実装して検証する
- 変更後に静的解析/整形/テストを実行する。
- Laravel プロジェクトでは `./vendor/bin/pint`、`./vendor/bin/phpstan analyse -l 6 --memory-limit=1G`、関連テストを実行する。

5. 結果を報告する
- 対象ファイル、変更内容、理由、影響範囲、未対応リスクを明記する。
- 見送り項目があれば「なぜ今やらないか」を書く。

## 重点チェック項目

- Variables
  - 意味が伝わる命名にする。
  - 同じ概念に異なる語彙を使わない。
  - マジックナンバーを定数化する。
- Functions
  - 引数を増やしすぎない（可能なら 2 以下）。
  - フラグ引数を避ける。
  - 副作用を局所化し、関数名と挙動を一致させる。
- Objects / Classes
  - 継承より合成を優先する。
  - 公開範囲を最小化する。
  - 1 クラス 1 責務を維持する。
- SOLID
  - SRP/OCP/LSP/ISP/DIP のどれを改善する変更か明示する。
- DRY
  - 重複を単純共通化する前に、意味的に同一かを確認する。

## 参照資料

- `references/clean-code-php-principles.md` を常に起点として使う。
- 詳細な指摘は「原則名 + 対象コード + 修正案 + リスク」で提示する。

## 出力フォーマット

1. 対象
- 例: `backend/app/Services/OrderService.php`

2. 問題点
- 原則名（Variables/Functions/Classes/SOLID/DRY）
- 現状の問題（再現条件含む）

3. 変更
- 何を変えたか
- なぜその変更が最小で安全か

4. 検証
- 実行したコマンド
- 結果（成功/失敗、残課題）
