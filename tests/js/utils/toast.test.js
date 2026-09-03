import toast from '@/utils/toast.js';

describe('ToastManager', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        toast.container.innerHTML = '';
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('init', () => {
        it('コンテナがDOMに追加される', () => {
            const container = document.getElementById('toast-container');
            expect(container).not.toBeNull();
            expect(container.parentNode).toBe(document.body);
        });
    });

    describe('getTypeStyles', () => {
        it('successのスタイルを返す', () => {
            expect(toast.getTypeStyles('success')).toContain('bg-green');
        });

        it('errorのスタイルを返す', () => {
            expect(toast.getTypeStyles('error')).toContain('bg-red');
        });

        it('warningのスタイルを返す', () => {
            expect(toast.getTypeStyles('warning')).toContain('bg-yellow');
        });

        it('infoのスタイルを返す', () => {
            expect(toast.getTypeStyles('info')).toContain('bg-blue');
        });

        it('不明なタイプはinfoのスタイルを返す', () => {
            expect(toast.getTypeStyles('unknown')).toBe(toast.getTypeStyles('info'));
        });
    });

    describe('getIcon', () => {
        it('各タイプでSVGを返す', () => {
            for (const type of ['success', 'error', 'warning', 'info']) {
                expect(toast.getIcon(type)).toContain('<svg');
            }
        });

        it('不明なタイプはinfoのアイコンを返す', () => {
            expect(toast.getIcon('unknown')).toBe(toast.getIcon('info'));
        });
    });

    describe('show', () => {
        it('Toast要素をコンテナに追加する', () => {
            toast.show('テストメッセージ');
            expect(toast.container.children.length).toBe(1);
        });

        it('メッセージテキストが含まれる', () => {
            toast.show('Hello World');
            expect(toast.container.textContent).toContain('Hello World');
        });

        it('閉じるボタンで削除できる', () => {
            toast.show('削除テスト', 'info', 0);
            const closeBtn = toast.container.querySelector('button');
            expect(closeBtn).not.toBeNull();
            closeBtn.click();
            // remove()は300msのアニメーション後に削除
            vi.advanceTimersByTime(300);
            expect(toast.container.children.length).toBe(0);
        });

        it('指定時間後に自動削除される', () => {
            toast.show('自動削除', 'info', 1000);
            expect(toast.container.children.length).toBe(1);
            vi.advanceTimersByTime(1000);
            // アニメーション300ms
            vi.advanceTimersByTime(300);
            expect(toast.container.children.length).toBe(0);
        });

        it('duration=0の場合は自動削除されない', () => {
            toast.show('永続', 'info', 0);
            vi.advanceTimersByTime(10000);
            expect(toast.container.children.length).toBe(1);
        });

        it('Toast要素を返す', () => {
            const el = toast.show('戻り値テスト');
            expect(el).toBeInstanceOf(HTMLDivElement);
        });
    });

    describe('remove', () => {
        it('アニメーションクラスを付与して300ms後にDOMから削除する', () => {
            const el = toast.show('削除対象', 'info', 0);
            toast.remove(el);
            expect(el.classList.contains('translate-x-full')).toBe(true);
            expect(el.classList.contains('opacity-0')).toBe(true);
            vi.advanceTimersByTime(300);
            expect(el.parentNode).toBeNull();
        });
    });

    describe('convenience methods', () => {
        it('success()はsuccessタイプで表示する', () => {
            const spy = vi.spyOn(toast, 'show');
            toast.success('OK');
            expect(spy).toHaveBeenCalledWith('OK', 'success', 3000);
        });

        it('error()はerrorタイプ・4秒表示で呼ぶ', () => {
            const spy = vi.spyOn(toast, 'show');
            toast.error('NG');
            expect(spy).toHaveBeenCalledWith('NG', 'error', 4000);
        });

        it('warning()はwarningタイプで表示する', () => {
            const spy = vi.spyOn(toast, 'show');
            toast.warning('注意');
            expect(spy).toHaveBeenCalledWith('注意', 'warning', 3000);
        });

        it('info()はinfoタイプで表示する', () => {
            const spy = vi.spyOn(toast, 'show');
            toast.info('情報');
            expect(spy).toHaveBeenCalledWith('情報', 'info', 3000);
        });
    });
});
