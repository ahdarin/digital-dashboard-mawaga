@php
    $contentBrief = $contentItem->contentBriefDraft;
@endphp

@if ($contentBrief)
    <div x-data="briefDiscussion({{ $contentBrief->id }})" class="card p-5 flex flex-col h-[520px]">
        <div class="flex items-center gap-1.5 mb-1">
            <span class="material-symbols-outlined text-[var(--brand)] text-[15px]">forum</span>
            <h3 class="text-sm font-semibold text-[var(--text-primary)]">Diskusi Brief AI</h3>
        </div>
        <p class="text-xs text-[var(--text-muted)] mb-4">
            Kasih masukan atau minta perubahan — AI usulkan revisi field yang relevan,
            terapkan lewat tombol di bawah tiap balasan.
        </p>

        <div class="flex-1 overflow-y-auto space-y-3 mb-3" x-ref="chatBox">
            <template x-for="(msg, i) in messages" :key="i">
                <div :class="msg.role === 'user' ? 'flex justify-end' : ''">
                    <div :class="msg.role === 'user'
                            ? 'bg-[var(--brand-solid)] text-white rounded-lg px-3 py-2 text-sm max-w-[90%]'
                            : 'bg-[var(--surface-muted)] text-[var(--text-primary)] rounded-lg px-3 py-2 text-sm max-w-[95%]'">
                        <p x-text="msg.content"></p>

                        <template x-if="msg.role === 'assistant' && msg.suggested_changes && Object.keys(msg.suggested_changes).length > 0">
                            <div class="mt-2 pt-2 border-t border-[var(--border-strong)]">
                                <p class="text-[10px] font-semibold text-[var(--text-secondary)] uppercase mb-1">Usulan perubahan:</p>
                                <template x-for="(val, key) in msg.suggested_changes" :key="key">
                                    <p class="text-[11px] text-[var(--text-secondary)]">
                                        <span x-text="key"></span>: <span class="font-medium" x-text="val"></span>
                                    </p>
                                </template>
                                <button type="button"
                                        x-on:click="applyChanges(msg.suggested_changes)"
                                        class="mt-2 text-xs font-medium text-[var(--brand)] hover:underline">
                                    Terapkan perubahan ini
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <div x-show="loading" x-cloak class="flex justify-start">
                <div class="bg-[var(--surface-muted)] rounded-lg px-3 py-2.5">
                    <div class="flex gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-[var(--text-placeholder)] animate-bounce" style="animation-delay:0ms"></span>
                        <span class="w-1.5 h-1.5 rounded-full bg-[var(--text-placeholder)] animate-bounce" style="animation-delay:150ms"></span>
                        <span class="w-1.5 h-1.5 rounded-full bg-[var(--text-placeholder)] animate-bounce" style="animation-delay:300ms"></span>
                    </div>
                </div>
            </div>
        </div>

        @if ($contentBrief->isLocked())
            <div class="bg-[var(--surface-muted)] rounded-lg p-3 text-center text-xs text-[var(--text-muted)]">
                Brief ini sudah diterapkan ke tim produksi, jadi tidak bisa didiskusikan lagi
                (biar tidak membingungkan Penanggung Jawab yang sedang mengerjakan).
                Tarik kembali dulu kalau mau revisi.
            </div>
        @else
            <div class="flex items-center gap-2">
                <input type="text" x-model="inputMessage" x-on:keydown.enter="send"
                       placeholder="Tulis masukan atau pertanyaan..."
                       class="bg-[var(--surface-card)] flex-1 border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
                <button type="button" x-on:click="send" :disabled="loading"
                        class="bg-[var(--brand-solid)] text-white w-9 h-9 rounded-lg flex items-center justify-center hover:bg-[var(--brand-dark)] disabled:opacity-50 shrink-0">
                    <span class="material-symbols-outlined text-[17px]">send</span>
                </button>
            </div>
        @endif
    </div>

    <script>
    function briefDiscussion(briefId) {
        return {
            briefId,
            messages: @json($contentBrief->chat_history ?? []),
            inputMessage: '',
            loading: false,

            async send() {
                if (!this.inputMessage.trim() || this.loading) return;
                const text = this.inputMessage;
                this.inputMessage = '';
                this.messages.push({ role: 'user', content: text });
                this.loading = true;
                this.$nextTick(() => {
                    this.$refs.chatBox.scrollTop = this.$refs.chatBox.scrollHeight;
                });

                try {
                    const res = await fetch(`/content-brief/${this.briefId}/discuss`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ message: text }),
                    });
                    const data = await res.json();
                    this.messages.push({
                        role: 'assistant',
                        content: data.reply,
                        suggested_changes: data.suggested_changes,
                    });
                } catch (e) {
                    this.messages.push({ role: 'assistant', content: 'Maaf, ada gangguan. Coba lagi.' });
                } finally {
                    this.loading = false;
                    this.$nextTick(() => {
                        this.$refs.chatBox.scrollTop = this.$refs.chatBox.scrollHeight;
                    });
                }
            },

            async applyChanges(fields) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = `/content-brief/${this.briefId}/apply`;

                const csrf = document.createElement('input');
                csrf.type = 'hidden'; csrf.name = '_token';
                csrf.value = document.querySelector('meta[name="csrf-token"]').content;
                form.appendChild(csrf);

                for (const key in fields) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `fields[${key}]`;
                    input.value = fields[key];
                    form.appendChild(input);
                }

                document.body.appendChild(form);
                form.requestSubmit();
            }
        }
    }
    </script>
@endif
