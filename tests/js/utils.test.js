import { escapeHTML, toggleButtonDisabled, formatDate } from '@/utils.js';

describe('escapeHTML', () => {
    it('HTMLタグをエスケープする', () => {
        expect(escapeHTML('<script>alert("xss")</script>')).toBe(
            '&lt;script&gt;alert("xss")&lt;/script&gt;'
        );
    });

    it('アンパサンドをエスケープする', () => {
        expect(escapeHTML('foo & bar')).toBe('foo &amp; bar');
    });

    it('通常テキストはそのまま返す', () => {
        expect(escapeHTML('hello world')).toBe('hello world');
    });

    it('空文字列を返す', () => {
        expect(escapeHTML('')).toBe('');
    });

    it('日本語テキストはそのまま返す', () => {
        expect(escapeHTML('夜に駆ける / YOASOBI')).toBe('夜に駆ける / YOASOBI');
    });
});

describe('toggleButtonDisabled', () => {
    it('trueでボタンを無効化する', () => {
        const btn = document.createElement('button');
        toggleButtonDisabled(btn, true);
        expect(btn.disabled).toBe(true);
        expect(btn.classList.contains('opacity-50')).toBe(true);
        expect(btn.classList.contains('cursor-not-allowed')).toBe(true);
    });

    it('falseでボタンを有効化する', () => {
        const btn = document.createElement('button');
        btn.disabled = true;
        btn.classList.add('opacity-50', 'cursor-not-allowed');
        toggleButtonDisabled(btn, false);
        expect(btn.disabled).toBe(false);
        expect(btn.classList.contains('opacity-50')).toBe(false);
        expect(btn.classList.contains('cursor-not-allowed')).toBe(false);
    });
});

describe('formatDate', () => {
    it('日付文字列をフォーマットする', () => {
        expect(formatDate('2024-11-11')).toMatch(/2024年11月1[01]日/);
    });

    it('空文字列で空を返す', () => {
        expect(formatDate('')).toBe('');
    });

    it('nullで空を返す', () => {
        expect(formatDate(null)).toBe('');
    });

    it('undefinedで空を返す', () => {
        expect(formatDate(undefined)).toBe('');
    });

    it('不正な日付文字列で空を返す', () => {
        expect(formatDate('invalid-date')).toBe('');
    });
});
