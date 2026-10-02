import { ArtistTagSyncDialog } from '@/songs/components/ArtistTagSyncDialog.js';

describe('ArtistTagSyncDialog', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    const matchingTags = [{ value: 'Kana Nishino' }];

    describe('show', () => {
        it('表示時に「タグも更新する」ボタンへフォーカスを移す', () => {
            const input = document.createElement('input');
            document.body.appendChild(input);
            input.focus();

            ArtistTagSyncDialog.show('Kana Nishino', '西野カナ', matchingTags);

            expect(document.activeElement?.id).toBe('syncTagsBtn');
        });

        it('「タグも更新する」で sync を返しダイアログを閉じる', async () => {
            const promise = ArtistTagSyncDialog.show('Kana Nishino', '西野カナ', matchingTags);
            document.getElementById('syncTagsBtn').click();

            await expect(promise).resolves.toEqual({ action: 'sync' });
            expect(document.getElementById('artistTagSyncDialog')).toBeNull();
        });

        it('「タグはそのまま」で skip を返しダイアログを閉じる', async () => {
            const promise = ArtistTagSyncDialog.show('Kana Nishino', '西野カナ', matchingTags);
            document.getElementById('skipTagsBtn').click();

            await expect(promise).resolves.toEqual({ action: 'skip' });
            expect(document.getElementById('artistTagSyncDialog')).toBeNull();
        });

        it('重ねて表示されても、それぞれのダイアログのボタンが自身の結果を返す', async () => {
            const first = ArtistTagSyncDialog.show('Kana Nishino', '西野カナ', matchingTags);
            const second = ArtistTagSyncDialog.show('Kana Nishino', '西野カナ', matchingTags);

            const dialogs = document.querySelectorAll('#artistTagSyncDialog');
            expect(dialogs).toHaveLength(2);

            // 前面（後から挿入された）ダイアログのボタンが反応すること
            dialogs[1].querySelector('[data-action="skip"]').click();
            await expect(second).resolves.toEqual({ action: 'skip' });

            dialogs[0].querySelector('[data-action="sync"]').click();
            await expect(first).resolves.toEqual({ action: 'sync' });
            expect(document.querySelectorAll('#artistTagSyncDialog')).toHaveLength(0);
        });
    });
});
