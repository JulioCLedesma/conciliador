export function catalogPanel(side) {
    return {
        side,
        busy: false,
        failed: false,
        observer: null,
        unhook: null,
        rejectLoad: null,
        savedScroll: null,
        init() {
            this.unhook?.();
            this.unhook = this.$wire.$hook('commit', ({ commit, fail, respond }) => {
                if (commit.calls.some(call => call.method === 'loadMore' && call.params[0] === this.side)) {
                    respond(() => { this.savedScroll = this.$refs.rows.scrollTop; });
                    fail(() => this.rejectLoad?.(new Error('Catalog load failed')));
                }
            });
            this.$watch('actionHeight', () => this.fit());
            this.$watch('catalogBusy', busy => { if (!busy) this.$nextTick(() => this.check()); });
            this.$nextTick(() => {
                this.fit();
                this.observer?.disconnect();
                this.observer = new IntersectionObserver(entries => {
                    if (entries.some(entry => entry.isIntersecting)) this.check();
                }, { root: this.$refs.rows, rootMargin: '160px 0px' });
                this.observer.observe(this.$refs.sentinel);
            });
        },
        destroy() {
            this.observer?.disconnect();
            this.unhook?.();
        },
        fit() {
            const top = Math.max(16, this.$el.getBoundingClientRect().top);
            const reserved = this.actionHeight || 140;
            this.$el.style.height = `${Math.max(240, window.innerHeight - top - reserved - 24)}px`;
        },
        isSelected(id) {
            return Object.prototype.hasOwnProperty.call(this.$wire.selected[this.side], id);
        },
        allLoadedSelected() {
            const ids = this.$wire.loadedIds[this.side];
            return ids.length > 0 && ids.every(id => this.isSelected(id));
        },
        someLoadedSelected() {
            return this.$wire.loadedIds[this.side].some(id => this.isSelected(id));
        },
        check() {
            const rows = this.$refs.rows;
            if (!this.failed && rows.scrollHeight - rows.scrollTop - rows.clientHeight < 180) this.more();
        },
        async more() {
            if (this.busy || this.catalogBusy || !this.$wire.hasMore[this.side]) return;
            this.busy = true;
            this.catalogBusy = true;
            this.failed = false;
            this.savedScroll = null;
            const generation = this.$wire.generation[this.side];
            try {
                const failure = new Promise((_, reject) => { this.rejectLoad = reject; });
                await Promise.race([
                    this.$wire.loadMore(this.side, this.$wire.loadedIds[this.side].length, generation),
                    failure,
                ]);
                await this.$nextTick();
                if (generation === this.$wire.generation[this.side] && this.savedScroll !== null) this.$refs.rows.scrollTop = this.savedScroll;
            } catch {
                this.failed = true;
            } finally {
                this.rejectLoad = null;
                this.busy = false;
                this.catalogBusy = false;
            }
            if (!this.failed) this.$nextTick(() => this.check());
        },
        reset(side) {
            if (side !== this.side) return;
            this.failed = false;
            this.$nextTick(() => {
                this.$refs.rows.scrollTop = 0;
                this.check();
            });
        },
    };
}

export function reconciliationBoard() {
    return {
        catalogBusy: false,
        showSelected: false,
        actionHeight: 0,
        observer: null,
        unhook: null,
        init() {
            this.unhook = this.$wire.$hook('request', ({ payload, fail }) => {
                const components = JSON.parse(payload).components;
                if (components.length !== 1) return;
                const request = components[0];
                if (JSON.parse(request.snapshot).memo.id !== this.$wire.$id
                    || Object.keys(request.updates).length
                    || !request.calls.length
                    || !request.calls.every(call => call.method === 'loadMore')) return;
                fail(({ status, preventDefault }) => {
                    if (status === 500 || status === 503) preventDefault();
                });
            });
            this.$nextTick(() => {
                this.observer = new ResizeObserver(() => {
                    this.actionHeight = this.$refs.actions.offsetHeight;
                    this.$el.style.setProperty('--actions-height', `${this.actionHeight}px`);
                });
                this.observer.observe(this.$refs.actions);
            });
        },
        destroy() {
            this.observer?.disconnect();
            this.unhook?.();
        },
    };
}
