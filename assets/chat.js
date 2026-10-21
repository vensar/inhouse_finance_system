(() => {
    const cfg = window.ecChat,
        form = document.getElementById("composer");
    if (!cfg) return;
    const list = document.getElementById("message-list"),
        box = document.getElementById("messages"),
        older = document.getElementById("older"),
        error = document.getElementById("chat-error");
    let last = 0,
        first = 0,
        busy = false;
    const seen = new Set();
    async function api(url, options) {
        const response = await fetch(url, { credentials: "same-origin", ...options });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.error || "Request failed.");
        return data;
    }
    function render(rows, prepend = false) {
        const stick = box.scrollHeight - box.scrollTop - box.clientHeight < 90,
            oldHeight = box.scrollHeight,
            fragment = document.createDocumentFragment();
        rows.forEach((m) => {
            if (seen.has(Number(m.id))) return;
            seen.add(Number(m.id));
            last = Math.max(last, Number(m.id));
            first = first ? Math.min(first, Number(m.id)) : Number(m.id);
            const item = document.createElement("div"),
                bubble = document.createElement("div"),
                time = document.createElement("time");
            item.className = "message " + (Number(m.sender_id) === cfg.user ? "sent" : "received");
            bubble.className = "bubble";
            bubble.textContent = m.message;
            time.textContent = m.created_at;
            item.append(bubble, time);
            fragment.append(item);
        });
        if (prepend) {
            list.prepend(fragment);
            box.scrollTop += box.scrollHeight - oldHeight;
        } else {
            list.append(fragment);
            if (stick) box.scrollTop = box.scrollHeight;
        }
    }
    async function poll() {
        if (!form || busy || document.hidden) return;
        busy = true;
        try {
            const initial = !last,
                data = await api("api/chat_api?peer=" + cfg.peer + "&after=" + last);
            render(data.messages);
            if (initial) older.hidden = !data.has_more;
            error.textContent = "";
        } catch (e) {
            error.textContent = e.message;
        } finally {
            busy = false;
        }
    }
    if (form) {
        form.addEventListener("submit", async (e) => {
            e.preventDefault();
            const button = form.querySelector("button"),
                field = form.querySelector("textarea");
            if (button.disabled) return;
            button.disabled = true;
            error.textContent = "";
            const text = field.value;
            try {
                await api("api/chat_api", {
                    method: "POST",
                    body: new URLSearchParams({
                        action: "send_message",
                        receiver_id: cfg.peer,
                        message: text,
                        csrf_token: cfg.csrf,
                    }),
                });
                if (field.value === text) field.value = "";
                await poll();
                box.scrollTop = box.scrollHeight;
            } catch (e) {
                error.textContent = e.message + " Your draft has been kept.";
            } finally {
                button.disabled = false;
                field.focus();
            }
        });
        older.addEventListener("click", async () => {
            older.disabled = true;
            try {
                const data = await api("api/chat_api?peer=" + cfg.peer + "&before=" + first);
                render(data.messages, true);
                older.hidden = !data.has_more;
            } catch (e) {
                error.textContent = e.message;
            } finally {
                older.disabled = false;
            }
        });
        poll();
        setInterval(poll, 5000);
        document.addEventListener("visibilitychange", () => {
            if (!document.hidden) poll();
        });
    }
    async function contacts() {
        if (document.hidden) return;
        try {
            const data = await api("api/chat_api?action=contacts");
            for (const c of data.contacts) {
                const link = document.querySelector('[data-peer="' + Number(c.id) + '"]');
                if (!link) continue;
                let badge = link.querySelector(".badge");
                if (Number(c.unread) > 0) {
                    if (!badge) {
                        badge = document.createElement("span");
                        badge.className = "badge bg-danger";
                        link.prepend(badge);
                    }
                    badge.textContent = c.unread;
                } else if (badge) badge.remove();
            }
        } catch (e) {
            /* Conversation errors are shown by poll. */
        }
    }
    setInterval(contacts, 15000);
})();
