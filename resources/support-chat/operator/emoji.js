// Compact, accessible emoji picker for Operator V3 matching Customer V2 exactly
const CUSTOMER_EMOJIS = ['🙂', '😊', '🙏', '👍', '❤️'];

export function operatorEmojiPicker({ host, input, toggle, changed }) {
  if (!host || !toggle || !input) return null;

  let keepInputFocus = false;
  let caret = [input.value.length, input.value.length];

  const updateCaret = () => {
    if (typeof input.selectionStart === 'number') {
      caret = [input.selectionStart, input.selectionEnd];
    }
  };

  input.addEventListener('blur', updateCaret);
  input.addEventListener('pointerdown', updateCaret);
  input.addEventListener('select', updateCaret);
  input.addEventListener('input', updateCaret);
  input.addEventListener('keyup', updateCaret);
  toggle.addEventListener('pointerdown', event => {
    keepInputFocus = document.activeElement === input;
    if (keepInputFocus) event.preventDefault();
    if (typeof input.selectionStart === 'number') {
      caret = [input.selectionStart, input.selectionEnd];
    }
  });

  const picker = document.createElement('section');
  picker.id = 'operatorEmojiPicker';
  picker.className = 'operator-emoji-picker';
  picker.setAttribute('role', 'dialog');
  picker.setAttribute('aria-label', 'شکلک‌ها');
  picker.hidden = true;

  for (const value of CUSTOMER_EMOJIS) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'emoji-btn';
    btn.setAttribute('aria-label', value);
    btn.textContent = value;
    btn.addEventListener('pointerdown', event => { if (keepInputFocus) event.preventDefault(); });
    btn.onclick = () => {
      insert(value);
    };
    picker.append(btn);
  }

  host.append(picker);

  const insert = (emojiChar) => {
    let [start, end] = caret;
    if (typeof input.selectionStart === 'number' && document.activeElement === input) {
      start = input.selectionStart;
      end = input.selectionEnd;
    }
    input.setRangeText(emojiChar, start, end, 'end');
    const nextPos = start + emojiChar.length;
    caret = [nextPos, nextPos];
    input.dispatchEvent(new Event('input', { bubbles: true }));
    if (typeof changed === 'function') changed();
    close();
    if (keepInputFocus) input.focus({preventScroll:true});
  };

  const open = () => {
    if (typeof input.selectionStart === 'number') {
      caret = [input.selectionStart, input.selectionEnd];
    }
    picker.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
    const firstBtn = picker.querySelector('button');
    if (firstBtn && !keepInputFocus && matchMedia('(pointer:fine)').matches) firstBtn.focus();
  };

  const close = () => {
    if (picker.hidden) return;
    picker.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
  };

  toggle.onclick = () => {
    if (picker.hidden) {
      if (document.activeElement === input && typeof input.selectionStart === 'number') {
        caret = [input.selectionStart, input.selectionEnd];
      }
      open();
    } else {
      close();
    }
  };

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !picker.hidden) {
      event.preventDefault();
      event.stopPropagation();
      close();
      if (keepInputFocus) input.focus({preventScroll:true});
    }
  }, true);

  document.addEventListener('pointerdown', event => {
    if (!picker.hidden && !picker.contains(event.target) && !toggle.contains(event.target)) {
      close();
    }
  });

  return {
    open,
    close,
    isOpen: () => !picker.hidden,
    destroy: () => {
      close();
      picker.remove();
    }
  };
}
