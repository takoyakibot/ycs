import { SimilarSongsDialog } from '@/songs/components/SimilarSongsDialog.js';

describe('SimilarSongsDialog', () => {
    afterEach(() => {
        const dialog = document.getElementById('similarSongsDialog');
        if (dialog) dialog.remove();
    });

    describe('escapeHtml', () => {
        it('HTMLタグをエスケープする', () => {
            expect(SimilarSongsDialog.escapeHtml('<script>alert("xss")</script>')).toBe(
                '&lt;script&gt;alert("xss")&lt;/script&gt;'
            );
        });

        it('アンパサンドをエスケープする', () => {
            expect(SimilarSongsDialog.escapeHtml('A & B')).toBe('A &amp; B');
        });

        it('通常の文字列はそのまま返す', () => {
            expect(SimilarSongsDialog.escapeHtml('Hello World')).toBe('Hello World');
        });

        it('空文字列はそのまま返す', () => {
            expect(SimilarSongsDialog.escapeHtml('')).toBe('');
        });
    });

    describe('show', () => {
        const similarSongs = [
            {
                song: { id: 'song1', title: '夜に駆ける', artist: 'YOASOBI' },
                similarity: 95,
                title_similarity: 100,
                artist_similarity: 90,
            },
            {
                song: { id: 'song2', title: '夜に駆ける (Live)', artist: 'YOASOBI' },
                similarity: 85,
                title_similarity: 80,
                artist_similarity: 90,
            },
        ];

        const inputData = { title: '夜に駆ける', artist: 'YOASOBI' };

        it('ダイアログがDOMに追加される', () => {
            SimilarSongsDialog.show(similarSongs, inputData);
            expect(document.getElementById('similarSongsDialog')).not.toBeNull();
        });

        it('入力データが表示される', () => {
            SimilarSongsDialog.show(similarSongs, inputData);
            const dialog = document.getElementById('similarSongsDialog');
            expect(dialog.textContent).toContain('夜に駆ける');
            expect(dialog.textContent).toContain('YOASOBI');
        });

        it('類似曲のリストが表示される', () => {
            SimilarSongsDialog.show(similarSongs, inputData);
            const items = document.querySelectorAll('.similar-song-item');
            expect(items.length).toBe(2);
        });

        it('楽曲を選択すると「選択した楽曲を使用」ボタンが有効になる', () => {
            SimilarSongsDialog.show(similarSongs, inputData);
            const useBtn = document.getElementById('useExistingSongBtn');
            expect(useBtn.disabled).toBe(true);

            const firstItem = document.querySelector('.similar-song-item');
            firstItem.click();
            expect(useBtn.disabled).toBe(false);
        });

        it('楽曲を選択して「使用」でuse_existingが返る', async () => {
            const promise = SimilarSongsDialog.show(similarSongs, inputData);

            document.querySelector('.similar-song-item').click();
            document.getElementById('useExistingSongBtn').click();

            const result = await promise;
            expect(result).toEqual({ action: 'use_existing', songId: 'song1' });
        });

        it('「新規登録」ボタンでforce_createが返る', async () => {
            const promise = SimilarSongsDialog.show(similarSongs, inputData);

            document.getElementById('forceCreateNewBtn').click();

            const result = await promise;
            expect(result).toEqual({ action: 'force_create' });
        });

        it('「キャンセル」ボタンでcancelが返る', async () => {
            const promise = SimilarSongsDialog.show(similarSongs, inputData);

            document.getElementById('cancelDialogBtn').click();

            const result = await promise;
            expect(result).toEqual({ action: 'cancel' });
        });

        it('ダイアログはアクション後にDOMから削除される', async () => {
            const promise = SimilarSongsDialog.show(similarSongs, inputData);
            document.getElementById('cancelDialogBtn').click();
            await promise;
            expect(document.getElementById('similarSongsDialog')).toBeNull();
        });

        it('2番目の楽曲を選択するとそのIDが使われる', async () => {
            const promise = SimilarSongsDialog.show(similarSongs, inputData);

            const items = document.querySelectorAll('.similar-song-item');
            items[1].click();
            document.getElementById('useExistingSongBtn').click();

            const result = await promise;
            expect(result).toEqual({ action: 'use_existing', songId: 'song2' });
        });

        it('選択をやり直すと新しい選択が反映される', async () => {
            const promise = SimilarSongsDialog.show(similarSongs, inputData);

            const items = document.querySelectorAll('.similar-song-item');
            items[0].click();
            items[1].click();
            document.getElementById('useExistingSongBtn').click();

            const result = await promise;
            expect(result.songId).toBe('song2');
        });
    });
});
