<?php

namespace Tests\Unit\Services;

use App\Helpers\TextNormalizer;
use App\Models\TimestampDecomposition;
use App\Models\User;
use App\Services\TimestampDecompositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TimestampDecompositionServiceTest extends TestCase
{
    use RefreshDatabase;

    private TimestampDecompositionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TimestampDecompositionService;
    }

    /**
     * カスケード処理: 同じアーティストを持つpendingなタイムスタンプが処理されることをテスト
     */
    public function test_cascade_artist_selection_processes_matching_timestamps(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // ソースとなるタイムスタンプ（選別元）
        $sourceDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('星街すいせい / Stellar Stellar'),
            'original_text' => '星街すいせい / Stellar Stellar',
            'parts' => ['星街すいせい', 'Stellar Stellar'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード対象となるタイムスタンプ（同じアーティスト）
        $targetDecomposition1 = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('星街すいせい / GHOST'),
            'original_text' => '星街すいせい / GHOST',
            'parts' => ['星街すいせい', 'GHOST'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード対象となるタイムスタンプ（同じアーティスト、3パーツ）
        $targetDecomposition2 = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('星街すいせい / NEXT COLOR PLANET / cover'),
            'original_text' => '星街すいせい / NEXT COLOR PLANET / cover',
            'parts' => ['星街すいせい', 'NEXT COLOR PLANET', 'cover'],
            'separator_count' => 2,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.3,
        ]);

        // カスケード対象外のタイムスタンプ（異なるアーティスト）
        $otherDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('YOASOBI / 夜に駆ける'),
            'original_text' => 'YOASOBI / 夜に駆ける',
            'parts' => ['YOASOBI', '夜に駆ける'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード処理を実行
        $cascadedCount = $this->service->cascadeArtistSelection('星街すいせい', $sourceDecomposition->id);

        // 2件がカスケード処理されたことを確認
        $this->assertEquals(2, $cascadedCount);

        // target1が更新されたことを確認
        $target1 = $targetDecomposition1->fresh();
        $this->assertEquals(TimestampDecomposition::STATUS_AUTO_MATCHED, $target1->status);
        $this->assertEquals('星街すいせい', $target1->derived_artist);
        $this->assertEquals('GHOST', $target1->derived_title);
        $this->assertEquals(0, $target1->artist_part_index);
        $this->assertEquals(1, $target1->title_part_index);
        $this->assertEquals(0.9, $target1->confidence);

        // target2が更新されたことを確認（cover は無視キーワードなのでスキップ）
        $target2 = $targetDecomposition2->fresh();
        $this->assertEquals(TimestampDecomposition::STATUS_AUTO_MATCHED, $target2->status);
        $this->assertEquals('星街すいせい', $target2->derived_artist);
        $this->assertEquals('NEXT COLOR PLANET', $target2->derived_title);
        $this->assertEquals(0, $target2->artist_part_index);
        $this->assertEquals(1, $target2->title_part_index);

        // otherは更新されていないことを確認
        $other = $otherDecomposition->fresh();
        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $other->status);
        $this->assertNull($other->derived_artist);
        $this->assertNull($other->derived_title);
    }

    /**
     * saveSelectionでカスケード処理が実行されることをテスト
     */
    public function test_save_selection_triggers_cascade(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // ソースとなるタイムスタンプ
        $sourceDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('Ado / うっせぇわ'),
            'original_text' => 'Ado / うっせぇわ',
            'parts' => ['Ado', 'うっせぇわ'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード対象
        $targetDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('Ado / 踊'),
            'original_text' => 'Ado / 踊',
            'parts' => ['Ado', '踊'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // saveSelectionを実行（artistIndex=0, titleIndex=1）
        $result = $this->service->saveSelection($sourceDecomposition->id, 1, 0);

        // カスケード件数を確認
        $this->assertEquals(1, $result['cascaded_count']);

        // ソースが更新されたことを確認
        $source = $result['decomposition'];
        $this->assertEquals(TimestampDecomposition::STATUS_SELECTED, $source->status);
        $this->assertEquals('Ado', $source->derived_artist);
        $this->assertEquals('うっせぇわ', $source->derived_title);

        // ターゲットがカスケード処理されたことを確認
        $target = $targetDecomposition->fresh();
        $this->assertEquals(TimestampDecomposition::STATUS_AUTO_MATCHED, $target->status);
        $this->assertEquals('Ado', $target->derived_artist);
        $this->assertEquals('踊', $target->derived_title);
    }

    /**
     * saveSelectionでカスケード処理を無効化できることをテスト
     */
    public function test_save_selection_can_disable_cascade(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // ソースとなるタイムスタンプ
        $sourceDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('Ado / うっせぇわ'),
            'original_text' => 'Ado / うっせぇわ',
            'parts' => ['Ado', 'うっせぇわ'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード対象
        $targetDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('Ado / 踊'),
            'original_text' => 'Ado / 踊',
            'parts' => ['Ado', '踊'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // saveSelectionを実行（カスケード無効）
        $result = $this->service->saveSelection($sourceDecomposition->id, 1, 0, enableCascade: false);

        // カスケード件数が0であることを確認
        $this->assertEquals(0, $result['cascaded_count']);

        // ターゲットがカスケード処理されていないことを確認
        $target = $targetDecomposition->fresh();
        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $target->status);
        $this->assertNull($target->derived_artist);
    }

    /**
     * アーティストが設定されない場合はカスケード処理が実行されないことをテスト
     */
    public function test_save_selection_without_artist_does_not_cascade(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // ソースとなるタイムスタンプ
        $sourceDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('夜に駆ける / cover'),
            'original_text' => '夜に駆ける / cover',
            'parts' => ['夜に駆ける', 'cover'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // saveSelectionを実行（アーティストなし）
        $result = $this->service->saveSelection($sourceDecomposition->id, 0, null);

        // カスケード件数が0であることを確認
        $this->assertEquals(0, $result['cascaded_count']);
    }

    /**
     * 正規化されたアーティスト名でマッチングされることをテスト
     */
    public function test_cascade_matches_with_normalized_artist_name(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // ソース（全角のYOASOBI）
        $sourceDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('ＹＯＡＳＯＢＩ / 夜に駆ける'),
            'original_text' => 'ＹＯＡＳＯＢＩ / 夜に駆ける',
            'parts' => ['ＹＯＡＳＯＢＩ', '夜に駆ける'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード対象（半角のYOASOBI）
        $targetDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('YOASOBI / アイドル'),
            'original_text' => 'YOASOBI / アイドル',
            'parts' => ['YOASOBI', 'アイドル'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード処理を実行（全角のYOASOBIを指定）
        $cascadedCount = $this->service->cascadeArtistSelection('ＹＯＡＳＯＢＩ', $sourceDecomposition->id);

        // 正規化後にマッチするため1件が処理されることを確認
        $this->assertEquals(1, $cascadedCount);

        $target = $targetDecomposition->fresh();
        $this->assertEquals(TimestampDecomposition::STATUS_AUTO_MATCHED, $target->status);
        $this->assertEquals('ＹＯＡＳＯＢＩ', $target->derived_artist); // 元のアーティスト名が保持される
    }

    /**
     * 無視キーワードがアーティストとしてマッチしないことをテスト
     */
    public function test_cascade_ignores_ignore_keywords(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // ソース
        $sourceDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('cover / 夜に駆ける'),
            'original_text' => 'cover / 夜に駆ける',
            'parts' => ['cover', '夜に駆ける'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード対象（coverがパーツにある）
        $targetDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('cover / アイドル'),
            'original_text' => 'cover / アイドル',
            'parts' => ['cover', 'アイドル'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード処理を実行（coverをアーティストとして指定）
        $cascadedCount = $this->service->cascadeArtistSelection('cover', $sourceDecomposition->id);

        // coverは無視キーワードなのでマッチしない
        $this->assertEquals(0, $cascadedCount);

        $target = $targetDecomposition->fresh();
        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $target->status);
    }
}
