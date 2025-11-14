'use strict';
(function (global) {
  const DEFAULTS = {
    minChars: 2,
    hideDelay: 160,
    fetchUrl: '',
    listElement: null,
    hiddenInput: null,
    requestParams: () => ({}),
    parseResponse: (data) => (Array.isArray(data?.results) ? data.results : []),
    renderItem: (item) => (
      '<div class="fw-semibold">' + escapeHtml(item.label || '') + '</div>'
    ),
    renderEmpty: () => '<div class="list-group-item text-muted">No matches</div>',
    renderLoading: () => '<div class="list-group-item text-muted">Searching…</div>',
    renderError: () => '<div class="list-group-item list-group-item-danger">Unable to load results</div>',
    onSelect: null,
    onResults: null,
    onError: null,
  };

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function ensureListElement(listEl) {
    if (!listEl) return false;
    listEl.setAttribute('role', 'listbox');
    return true;
  }

  function createListItem(content, index) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'list-group-item list-group-item-action text-start';
    button.dataset.autocompleteIndex = String(index);
    button.setAttribute('role', 'option');
    button.innerHTML = content;
    return button;
  }

  function StudentAutocomplete(inputEl, options) {
    if (!inputEl) return null;
    const config = Object.assign({}, DEFAULTS, options || {});
    const listEl = config.listElement;
    if (!ensureListElement(listEl)) return null;

    let abortController = null;
    let items = [];
    let activeIndex = -1;
    let hideTimer = null;

    function clearHideTimer() {
      if (hideTimer) {
        clearTimeout(hideTimer);
        hideTimer = null;
      }
    }

    function hideList() {
      clearHideTimer();
      activeIndex = -1;
      listEl.classList.add('d-none');
      listEl.innerHTML = '';
    }

    function showList() {
      clearHideTimer();
      listEl.classList.remove('d-none');
    }

    function setActive(index, options = { scroll: true }) {
      activeIndex = index;
      const nodes = listEl.querySelectorAll('[data-autocomplete-index]');
      nodes.forEach((node, idx) => {
        if (idx === activeIndex) {
          node.classList.add('active');
          if (options.scroll) {
            node.scrollIntoView({ block: 'nearest' });
          }
        } else {
          node.classList.remove('active');
        }
      });
    }

    function selectItem(index) {
      const item = items[index];
      if (!item) return;
      if (config.hiddenInput) {
        config.hiddenInput.value = item.id != null ? String(item.id) : '';
      }
      if (typeof config.onSelect === 'function') {
        config.onSelect(item, { input: inputEl, list: listEl });
      }
      hideList();
    }

    function renderItems(nextItems) {
      items = nextItems;
      listEl.innerHTML = '';
      if (!items.length) {
        listEl.innerHTML = config.renderEmpty();
        showList();
        return;
      }
      items.forEach((item, index) => {
        const raw = config.renderItem(item, { escapeHtml });
        const content = raw instanceof Node ? raw.outerHTML : String(raw ?? '');
        const node = createListItem(content, index);
        node.addEventListener('mouseenter', () => {
          setActive(index, { scroll: false });
        });
        node.addEventListener('click', (event) => {
          event.preventDefault();
          selectItem(index);
        });
        listEl.appendChild(node);
      });
      setActive(-1);
      showList();
    }

    function renderStateMarkup(markup) {
      listEl.innerHTML = markup;
      showList();
    }

    function buildUrl(query) {
      const base = typeof config.fetchUrl === 'function' ? config.fetchUrl(query) : config.fetchUrl;
      if (!base) return '';
      const url = new URL(base, document.baseURI);
      url.searchParams.set('q', query);
      const extra = config.requestParams();
      if (extra && typeof extra === 'object') {
        Object.entries(extra).forEach(([key, value]) => {
          if (value !== undefined && value !== null) {
            url.searchParams.set(key, value);
          }
        });
      }
      return url.toString();
    }

    function performSearch(query) {
      if (!query || query.length < config.minChars) {
        hideList();
        return;
      }

      if (abortController) {
        abortController.abort();
      }
      abortController = new AbortController();

      renderStateMarkup(config.renderLoading());

      const requestUrl = buildUrl(query);
      if (!requestUrl) {
        hideList();
        return;
      }

      fetch(requestUrl, {
        credentials: 'same-origin',
        signal: abortController.signal,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then((response) => {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      }).then((data) => {
        const parsed = config.parseResponse(data) || [];
        renderItems(parsed);
        if (typeof config.onResults === 'function') {
          config.onResults(parsed);
        }
      }).catch((error) => {
        if (error.name === 'AbortError') return;
        renderStateMarkup(config.renderError(error));
        if (typeof config.onError === 'function') {
          config.onError(error);
        }
      });
    }

    inputEl.addEventListener('input', () => {
      if (config.hiddenInput) {
        config.hiddenInput.value = '';
      }
      const value = inputEl.value.trim();
      if (value.length < config.minChars) {
        hideList();
        return;
      }
      performSearch(value);
    });

    inputEl.addEventListener('keydown', (event) => {
      if (listEl.classList.contains('d-none')) {
        return;
      }
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (!items.length) return;
        const next = (activeIndex + 1) % items.length;
        setActive(next);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        if (!items.length) return;
        const prev = activeIndex <= 0 ? items.length - 1 : activeIndex - 1;
        setActive(prev);
      } else if (event.key === 'Enter') {
        if (activeIndex >= 0 && items[activeIndex]) {
          event.preventDefault();
          selectItem(activeIndex);
        }
      } else if (event.key === 'Escape') {
        hideList();
      }
    });

    inputEl.addEventListener('focus', () => {
      if (listEl.children.length > 0) {
        showList();
      }
    });

    inputEl.addEventListener('blur', () => {
      clearHideTimer();
      hideTimer = setTimeout(hideList, config.hideDelay);
    });

    listEl.addEventListener('mousedown', (event) => {
      // Prevent the input blur from firing before we process clicks.
      event.preventDefault();
    });

    function triggerSearch() {
      const value = inputEl.value.trim();
      if (value.length >= config.minChars) {
        performSearch(value);
      } else {
        hideList();
      }
    }

    function destroy() {
      hideList();
      inputEl.removeEventListener('input', performSearch);
    }

    return {
      triggerSearch,
      hide: hideList,
      destroy,
    };
  }

  global.StudentAutocomplete = Object.assign({}, global.StudentAutocomplete || {}, {
    init: StudentAutocomplete,
  });
})(window);
