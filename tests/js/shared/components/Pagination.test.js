import { Pagination } from '@/shared/components/Pagination.js';

describe('Pagination.calculateTotalPages', () => {
    it('件数とページサイズからページ数を計算する', () => {
        expect(Pagination.calculateTotalPages(100, 10)).toBe(10);
        expect(Pagination.calculateTotalPages(101, 10)).toBe(11);
        expect(Pagination.calculateTotalPages(1, 10)).toBe(1);
        expect(Pagination.calculateTotalPages(0, 10)).toBe(0);
    });
});

describe('Pagination.isValidPage', () => {
    it('有効なページ番号を判定する', () => {
        expect(Pagination.isValidPage(1, 10)).toBe(true);
        expect(Pagination.isValidPage(10, 10)).toBe(true);
        expect(Pagination.isValidPage(5, 10)).toBe(true);
    });

    it('無効なページ番号を拒否する', () => {
        expect(Pagination.isValidPage(0, 10)).toBe(false);
        expect(Pagination.isValidPage(11, 10)).toBe(false);
        expect(Pagination.isValidPage(-1, 10)).toBe(false);
    });
});

describe('Pagination.normalizePage', () => {
    it('有効な値はそのまま返す', () => {
        expect(Pagination.normalizePage(5, 10)).toBe(5);
    });

    it('文字列をパースする', () => {
        expect(Pagination.normalizePage('3', 10)).toBe(3);
    });

    it('0以下は1に正規化する', () => {
        expect(Pagination.normalizePage(0, 10)).toBe(1);
        expect(Pagination.normalizePage(-5, 10)).toBe(1);
    });

    it('最終ページを超える値は最終ページに正規化する', () => {
        expect(Pagination.normalizePage(15, 10)).toBe(10);
    });

    it('NaNは1に正規化する', () => {
        expect(Pagination.normalizePage('abc', 10)).toBe(1);
    });
});

describe('Pagination.createButton', () => {
    it('有効なボタンを作成する', () => {
        const callback = vi.fn();
        const btn = Pagination.createButton('次へ', 2, true, callback);
        expect(btn.textContent).toBe('次へ');
        expect(btn.disabled).toBe(false);
        btn.click();
        expect(callback).toHaveBeenCalledWith(2);
    });

    it('無効なボタンを作成する', () => {
        const callback = vi.fn();
        const btn = Pagination.createButton('前へ', 0, false, callback);
        expect(btn.disabled).toBe(true);
        btn.click();
        expect(callback).not.toHaveBeenCalled();
    });
});

describe('Pagination.render', () => {
    let container;
    let callback;

    beforeEach(() => {
        container = document.createElement('div');
        callback = vi.fn();
    });

    it('1ページのみの場合はボタンを描画しない', () => {
        Pagination.render(container, { current_page: 1, last_page: 1 }, callback);
        expect(container.querySelectorAll('button').length).toBe(0);
    });

    it('1ページのみでもtotalがあれば件数を表示する', () => {
        Pagination.render(container, { current_page: 1, last_page: 1, total: 5 }, callback);
        expect(container.textContent).toContain('全5件');
    });

    it('複数ページの場合はナビゲーションボタンを描画する', () => {
        Pagination.render(container, { current_page: 5, last_page: 20 }, callback);
        const buttons = container.querySelectorAll('button');
        expect(buttons.length).toBeGreaterThan(0);
        expect(container.textContent).toContain('5 / 20');
    });

    it('最初のページでは前へ系ボタンが無効', () => {
        Pagination.render(container, { current_page: 1, last_page: 10 }, callback);
        const buttons = [...container.querySelectorAll('button')];
        const prevBtn = buttons.find(b => b.textContent === '前へ');
        expect(prevBtn.disabled).toBe(true);
    });

    it('最後のページでは次へ系ボタンが無効', () => {
        Pagination.render(container, { current_page: 10, last_page: 10 }, callback);
        const buttons = [...container.querySelectorAll('button')];
        const nextBtn = buttons.find(b => b.textContent === '次へ');
        expect(nextBtn.disabled).toBe(true);
    });

    it('ジャンプボタン非表示オプション', () => {
        Pagination.render(container, { current_page: 5, last_page: 20 }, callback, { showJumpButtons: false });
        const labels = [...container.querySelectorAll('button')].map(b => b.textContent);
        expect(labels).not.toContain('-5');
        expect(labels).not.toContain('+5');
    });

    it('totalがあれば件数を括弧付きで表示する', () => {
        Pagination.render(container, { current_page: 1, last_page: 5, total: 50 }, callback);
        expect(container.textContent).toContain('(全50件)');
    });
});
