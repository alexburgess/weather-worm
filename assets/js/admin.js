(function () {
  function updateInputDecorState(input) {
    var wrap = input.closest('.weather-worm-input-decor');
    if (!wrap) {
      return;
    }

    wrap.classList.toggle('is-filled', String(input.value || '').trim() !== '');
  }

  function initInputDecor() {
    document
      .querySelectorAll('.weather-worm-input-decor input')
      .forEach(function (input) {
        updateInputDecorState(input);
        input.addEventListener('input', function () {
          updateInputDecorState(input);
        });
      });
  }

  function initCopyButtons() {
    document.querySelectorAll('.weather-worm-copy-shortcode').forEach(function (button) {
      button.addEventListener('click', function () {
        var shortcode = button.getAttribute('data-shortcode') || '';
        var original = button.innerHTML;

        function markCopied() {
          button.textContent = 'Copied';
          window.setTimeout(function () {
            button.innerHTML = original;
          }, 1200);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(shortcode).then(markCopied);
          return;
        }

        var input = document.createElement('input');
        input.value = shortcode;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        input.remove();
        markCopied();
      });
    });
  }

  function initConfirmButtons() {
    document.querySelectorAll('[data-confirm]').forEach(function (button) {
      button.addEventListener('click', function (event) {
        var message = button.getAttribute('data-confirm') || '';
        if (message && !window.confirm(message)) {
          event.preventDefault();
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initInputDecor();
    initCopyButtons();
    initConfirmButtons();
  });
})();
