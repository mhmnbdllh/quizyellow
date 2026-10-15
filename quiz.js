// Quiz extension (customised build)
// Answers and page leaves are kept per attempt in this browser, so a refresh continues the running quiz.
// Copying is blocked, leaving the page for 10 seconds or more is counted (3 times = automatic submit).
// Certificates are drawn in the browser as a PDF, no library and no server work needed.
"use strict";
(function () {
    var PREFIX = "yquiz:";
    var AWAY_LIMIT = 10000;   // leaving the page this long (ms) counts once
    var AWAY_MAX = 3;         // after this many counts the answers are submitted

    function storeGet(key) {
        try { var value = window.localStorage.getItem(key); return value ? JSON.parse(value) : null; } catch (e) { return null; }
    }
    function storeSet(key, value) {
        try { window.localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* private mode or full */ }
    }
    function storeDel(key) {
        try { window.localStorage.removeItem(key); } catch (e) { /* ignore */ }
    }
    function getCookie(name) {
        var match = document.cookie.match(new RegExp("(?:^|;\\s*)" + name + "=([^;]*)"));
        return match ? decodeURIComponent(match[1]) : null;
    }
    function setCookie(name, value, seconds, path) {
        var secure = window.location.protocol === "https:" ? "; Secure" : "";
        document.cookie = name + "=" + encodeURIComponent(value) + "; path=" + path + "; max-age=" + seconds + "; SameSite=Lax" + secure;
    }
    function readJSON(element, attribute) {
        try { return JSON.parse(element.getAttribute(attribute) || "{}"); } catch (e) { return {}; }
    }
    function esc(text) {
        return String(text === null || text === undefined ? "" : text).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[c];
        });
    }
    function isField(element) {
        return !!(element && element.closest && element.closest("input, textarea, select, [contenteditable=true]"));
    }
    // Everything the quiz adds to the page is marked as English, whatever the language of the page
    function english(element) { element.setAttribute("lang", "en"); return element; }
    var LAST_DAYS = 30; // the last score is remembered for 30 days, the full result (cookie) for 24 hours
    function hasResult(id) { return !!getCookie("yquizr_" + id); }
    // Remove the result, the last score and the remembered name of this quiz from this browser (not the device stamp)
    function forgetQuiz(id) {
        setCookie("yquizr_" + id, "", 0, window.location.pathname);
        setCookie("yquizr_" + id, "", 0, "/"); // the server keeps a result for the whole site when the page address is unusual
        storeDel(PREFIX + "last:" + id);
        storeDel(PREFIX + "name");
    }
    // Printing a quiz page gives a white page with only the site name
    var printGuard = false;
    function guardPrinting(site) {
        if (printGuard) return;
        printGuard = true;
        document.documentElement.classList.add("quiz-print-guard");
        var note = document.createElement("div");
        note.className = "quiz-print-name";
        note.textContent = site || window.location.hostname;
        document.body.appendChild(english(note));
    }
    function toast(text) {
        if (!text) return;
        var old = document.querySelector(".quiz-toast");
        if (old) old.parentNode.removeChild(old);
        var box = document.createElement("div");
        box.className = "quiz-toast";
        box.setAttribute("role", "alert");
        box.textContent = text;
        document.body.appendChild(english(box));
        setTimeout(function () { if (box.parentNode) box.parentNode.removeChild(box); }, 7000);
    }

    document.addEventListener("DOMContentLoaded", function () {
        var all = document.querySelectorAll(".quiz-container:not(.quiz-data)");
        if (all.length) guardPrinting((readJSON(all[0].querySelector("[data-i18n]") || all[0], "data-i18n")).site);
        var boxes = document.querySelectorAll(".quiz-container[data-quiz-id]");
        for (var i = 0; i < boxes.length; i++) {
            if (boxes[i].classList.contains("quiz-protect")) protect(boxes[i]);
            if (boxes[i].classList.contains("quiz-intro")) initIntro(boxes[i]);
            else if (boxes[i].classList.contains("quiz-is-result")) initResult(boxes[i]);
            else if (boxes[i].querySelector("form.quiz-form")) initQuiz(boxes[i]);
            initMedia(boxes[i]);
        }
        var reprints = document.querySelectorAll(".quiz-reprint");
        for (var r = 0; r < reprints.length; r++) initReprint(reprints[r]);
        var apps = document.querySelectorAll(".quiz-data-app");
        for (var d = 0; d < apps.length; d++) initData(apps[d]);
    });

    // ---------------------------------------------------------------- copy protection
    // Blocks right click, text selection, copying, dragging, printing and common shortcuts inside the quiz
    var keysBound = false;
    function protect(box) {
        var stop = function (e) { if (!isField(e.target)) e.preventDefault(); };
        ["contextmenu", "copy", "cut", "dragstart", "selectstart"].forEach(function (type) { box.addEventListener(type, stop); });
        if (keysBound) return;
        keysBound = true;
        document.addEventListener("keydown", function (e) {
            if (!(e.ctrlKey || e.metaKey) || isField(e.target)) return;
            var key = String(e.key || "").toLowerCase();
            if (["c", "x", "a", "p", "s", "u"].indexOf(key) >= 0) e.preventDefault();
        });
        document.addEventListener("copy", function (e) {
            if (!isField(e.target) && window.getSelection && document.querySelector(".quiz-protect")) {
                var selection = window.getSelection();
                if (selection && selection.anchorNode && selection.anchorNode.parentElement && selection.anchorNode.parentElement.closest(".quiz-protect")) e.preventDefault();
            }
        });
    }

    // ---------------------------------------------------------------- quiz form
    function initQuiz(box) {
        var id = box.getAttribute("data-quiz-id");
        var attempt = box.getAttribute("data-attempt") || "";
        var minutes = parseInt(box.getAttribute("data-time"), 10) || 0;
        var remaining = parseInt(box.getAttribute("data-remaining"), 10) || 0;
        var t = readJSON(box, "data-i18n");
        var form = box.querySelector("form.quiz-form");
        var stateKey = PREFIX + id;
        var seenKey = PREFIX + "seen:" + id;
        if (!form) return;
        var field = function (name) { return form.querySelector("input[name=" + name + "]"); };

        // JavaScript is running: show the quiz and mark the submission as coming from a real browser
        box.classList.add("quiz-js-ready");
        if (field("quiz_client")) field("quiz_client").value = "1";
        guardRotation(t.rotateRunning);
        releaseHeaders();

        // Remove the retake marker from the address, the page is already a fresh attempt
        if (window.history && history.replaceState && /[?&]quiz_(retake|start)=/.test(window.location.search)) {
            var query = window.location.search.replace(/([?&])quiz_(retake|start)=[^&]*&?/g, "$1").replace(/[?&]$/, "");
            history.replaceState(null, "", window.location.pathname + query + window.location.hash);
        }

        // Answers and page leaves of this attempt; the "last seen" time is kept apart because it changes every second
        var state = storeGet(stateKey);
        if (!state || state.attempt !== attempt || typeof state.answers !== "object" || !state.answers) {
            state = { attempt: attempt, answers: {}, away: 0, awayStart: 0 };
        }
        if (!state.essays || typeof state.essays !== "object") state.essays = {};
        state.away = parseInt(state.away, 10) || 0;
        state.awayStart = parseInt(state.awayStart, 10) || 0;
        var save = function () { storeSet(stateKey, state); };
        var seenRecord = storeGet(seenKey);
        var lastSeen = seenRecord && seenRecord.a === attempt ? parseInt(seenRecord.t, 10) || 0 : 0;

        // Restore answers given before the refresh
        var radios = form.querySelectorAll("input[type=radio]");
        for (var i = 0; i < radios.length; i++) {
            if (state.answers[radios[i].name] === radios[i].value) radios[i].checked = true;
        }

        var groups = form.querySelectorAll(".quiz-q");
        var answeredEl = box.querySelector(".quiz-answered-count");
        // a question is answered when an option is chosen; an essay answer needs its number of words (at least one word)
        function isAnswered(card) {
            if (card.classList.contains("quiz-essay")) return essayStatus(card).ok;
            return !!card.querySelector("input[type=radio]:checked");
        }
        function getMissing() {
            var list = [];
            for (var g = 0; g < groups.length; g++) {
                if (!isAnswered(groups[g])) list.push(groups[g]);
            }
            return list;
        }
        function updateAnswered() {
            if (answeredEl) answeredEl.textContent = (groups.length - getMissing().length) + "/" + groups.length;
        }
        updateAnswered();
        form.addEventListener("change", function (e) {
            var el = e.target;
            if (el && el.type === "radio") {
                state.answers[el.name] = el.value;
                save();
                updateAnswered();
                var card = el.closest(".quiz-q");
                if (card) unmark(card);
            }
        });

        // ---- sending the answers
        var sent = false;
        var button = form.querySelector(".quiz-submit");
        var missingDialog = null;
        function submitNow(reason) {
            if (sent) return;
            sent = true;
            if (field("quiz_device") && !field("quiz_device").value) field("quiz_device").value = getDevice();
            if (field("quiz_away")) field("quiz_away").value = String(state.away);
            if (field("quiz_auto")) field("quiz_auto").value = reason || "";
            if (missingDialog) missingDialog.close();
            if (button) { button.disabled = true; button.classList.add("is-busy"); }
            showLoading(t.submitting);
            form.submit();
        }
        form.addEventListener("submit", function (e) {
            e.preventDefault();
            if (sent) return;
            var missing = getMissing();
            if (missing.length) {
                if (!missingDialog) missingDialog = createMissingDialog(t, goToCard);
                for (var m = 0; m < missing.length; m++) mark(missing[m]);
                missingDialog.open(missing);
            } else {
                submitNow("");
            }
        });
        // After the answers are sent, the page keeps nothing of the quiz: the back button brings no questions and no box
        // back (the page is loaded again: start popup). A second tab of the same attempt is emptied as soon as the
        // attempt ends elsewhere (sent, or a new attempt started) and shows the result.
        var wipe = function () { box.textContent = ""; };
        window.addEventListener("pagehide", function () { if (sent) wipe(); });
        window.addEventListener("pageshow", function (e) {
            if (e.persisted && sent) window.location.reload();
        });
        var attemptCookie = getCookie("yquiz_" + id);
        if (attemptCookie) {
            setInterval(function () {
                if (sent || getCookie("yquiz_" + id) === attemptCookie) return;
                sent = true;
                wipe();
                window.location.replace(window.location.pathname + "?result");
            }, 1000);
        }

        // ---- questions not answered yet: marked until they are answered
        function badgeText(card) {
            if (!card.classList.contains("quiz-essay")) return t.unansweredBadge || "";
            var status = essayStatus(card);
            if (status.words === 0) return t.unansweredBadge || "";
            if (status.words < status.min) return (t.essayTooShort || "").replace("@count", status.words).replace("@min", status.min);
            return (t.essayTooLong || "").replace("@count", status.words).replace("@max", status.max);
        }
        function mark(card) {
            var badge = card.querySelector(".quiz-missing-badge");
            if (!badge) {
                card.classList.add("is-missing");
                badge = document.createElement("span");
                badge.className = "quiz-missing-badge";
                card.appendChild(badge);
            }
            badge.textContent = badgeText(card);
        }
        function unmark(card) {
            card.classList.remove("is-missing");
            var badge = card.querySelector(".quiz-missing-badge");
            if (badge) badge.parentNode.removeChild(badge);
        }
        // the card stops below the timer, and below the floating box of its group
        function goToCard(card) {
            var top = card.getBoundingClientRect().top + (window.pageYOffset || document.documentElement.scrollTop) - Math.max(110, coverBottom(card) + 16);
            // near the end of the page there may be too little left to scroll: room is added under the quiz
            var missing = top - (document.documentElement.scrollHeight - window.innerHeight);
            if (missing > 0) form.style.paddingBottom = (parseFloat(form.style.paddingBottom) || 0) + Math.ceil(missing) + "px";
            try { window.scrollTo({ top: Math.max(0, top), behavior: "smooth" }); } catch (err) { window.scrollTo(0, Math.max(0, top)); }
            var first = card.querySelector("input[type=radio], textarea");
            if (first) { try { first.focus({ preventScroll: true }); } catch (err) { /* old browser */ } }
        }
        // ---- essay questions: word count, limits, draft in this browser, no pasting or help from the browser
        var essayCards = form.querySelectorAll(".quiz-essay");
        Array.prototype.forEach.call(essayCards, function (card) {
            var area = card.querySelector("textarea");
            if (!area) return;
            if (typeof state.essays[area.name] === "string") area.value = state.essays[area.name];
            var timer = null;
            var update = function () {
                showEssayCount(card, t);
                if (card.classList.contains("is-missing")) { if (isAnswered(card)) unmark(card); else mark(card); }
                updateAnswered();
                growEssay(area);
            };
            guardEssay(area, t, function () {
                update();
                if (timer) clearTimeout(timer);
                timer = setTimeout(function () { state.essays[area.name] = area.value; save(); }, 300);
            });
            update();
        });
        if (essayCards.length) {
            if (field("quiz_device")) field("quiz_device").value = getDevice();
            initTyping(box, form, t);
            // the draft is stored at once when the page is hidden or closed
            var saveDrafts = function () {
                Array.prototype.forEach.call(essayCards, function (card) { var area = card.querySelector("textarea"); if (area) state.essays[area.name] = area.value; });
                save();
            };
            window.addEventListener("pagehide", saveDrafts);
            document.addEventListener("visibilitychange", function () { if (document.visibilityState === "hidden") saveDrafts(); });
        }
        keepFocusVisible(form);

        // ---- leaving the page: 10 seconds or more counts once, the third count submits the answers.
        // Measured in this browser only, nothing is sent to the server until the answers are submitted.
        var lastTick = Date.now();
        function markSeen(now) {
            lastTick = now;
            storeSet(seenKey, { a: attempt, t: now });
        }
        function strike(milliseconds) {
            state.away++;
            save();
            if (state.away >= AWAY_MAX) {
                toast(t.awaySubmitted);
                submitNow("away");
            } else {
                toast((t.awayWarning || "").replace("@seconds", Math.round(milliseconds / 1000)).replace("@count", state.away));
            }
        }
        function goAway() {
            if (sent || state.awayStart) return;
            state.awayStart = Date.now();
            save();
        }
        // The page is visible again: this alone decides, phones often do not report focus when coming back
        function comeBack() {
            if (sent || document.visibilityState === "hidden") return;
            var now = Date.now();
            var start = state.awayStart || (now - lastTick >= AWAY_LIMIT ? lastTick : 0);
            if (state.awayStart) { state.awayStart = 0; save(); }
            markSeen(now);
            if (start && now - start >= AWAY_LIMIT) strike(now - start);
        }
        // Opening the page: the third count was reached before (for example the automatic submit failed)
        if (state.away >= AWAY_MAX) {
            toast(t.awaySubmitted);
            submitNow("away");
            return;
        }
        // Opening the page: it was closed or frozen while away, measure from the last moment it was seen
        var last = state.awayStart || lastSeen;
        if (state.awayStart) { state.awayStart = 0; save(); }
        markSeen(Date.now());
        if (last && Date.now() - last >= AWAY_LIMIT) strike(Date.now() - last);
        if (sent) return;
        if (document.visibilityState === "hidden") goAway();
        document.addEventListener("visibilitychange", function () {
            if (document.visibilityState === "hidden") goAway();
            else comeBack();
        });
        // A click into an iframe of the quiz (video, slides) moves the focus but the student stays on the page
        var inFrame = false;
        window.addEventListener("blur", function () {
            setTimeout(function () {
                var active = document.activeElement;
                if (active && active.tagName === "IFRAME" && box.contains(active)) { inFrame = true; return; }
                goAway();
            }, 0);
        });
        window.addEventListener("focus", function () { inFrame = false; comeBack(); });
        window.addEventListener("pageshow", comeBack);
        // Backup check once per second: a page that was frozen without any signal shows a gap in time
        setInterval(function () {
            if (inFrame && !sent && !state.awayStart) { // inside an iframe: leaving it for another window is leaving the page
                var active = document.activeElement;
                if (!document.hasFocus()) { inFrame = false; goAway(); return; }
                if (!active || active.tagName !== "IFRAME") inFrame = false;
            }
            if (sent || state.awayStart || document.visibilityState !== "visible") return;
            var now = Date.now();
            var gap = now - lastTick;
            markSeen(now);
            if (gap >= AWAY_LIMIT) strike(gap);
        }, 1000);

        // ---- closing time of the quiz: a banner under the timer, the attempt itself may continue
        var closeIn = box.getAttribute("data-close-in");
        var deadlineIn = box.getAttribute("data-deadline-in");
        if (closeIn !== null) {
            var closeAt = Date.now() + (parseInt(closeIn, 10) || 0) * 1000;
            var hasDeadline = deadlineIn !== null;
            var deadlineAt = Date.now() + (parseInt(deadlineIn, 10) || 0) * 1000;
            var banner = null;
            var showBanner = function (text) {
                if (!banner) {
                    banner = document.createElement("div");
                    banner.className = "quiz-schedule-banner" + (minutes > 0 ? "" : " is-alone");
                    banner.setAttribute("role", "status");
                    document.body.appendChild(english(banner));
                    queueWide();
                }
                if (banner.textContent !== text) banner.textContent = text;
            };
            var fill = function (text) {
                return (text || "").replace("@time", box.getAttribute("data-close-at") || "").replace("@end", box.getAttribute("data-end-at") || "");
            };
            var watchClosing = function () {
                if (sent) return;
                var now = Date.now();
                if (now >= closeAt) showBanner(fill(hasDeadline ? t.closedRunningNoLimit : t.closedRunning));
                else if (closeAt - now <= 600000) showBanner(fill(hasDeadline ? t.closingSoonNoLimit : t.closingSoon));
                if (hasDeadline && now >= deadlineAt) { toast(t.timeUp); submitNow("time"); }
            };
            watchClosing();
            setInterval(watchClosing, 1000);
        }

        // ---- timer: remaining time comes from the server, so the device clock does not matter
        if (minutes > 0) {
            var bar = box.querySelector(".quiz-progressbar");
            var text = box.querySelector(".quiz-progresstext");
            var pill = box.querySelector(".quiz-progress");
            var total = minutes * 60;
            var deadline = Date.now() + remaining * 1000;
            var timer = null;
            var tick = function () {
                var left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
                var m = Math.floor(left / 60), s = left % 60;
                var ratio = left / total;
                if (text) text.textContent = m + ":" + (s < 10 ? "0" : "") + s;
                if (bar) bar.style.width = (ratio * 100) + "%";
                if (pill) heat(pill, ratio);
                if (left <= 0 && !sent) {
                    if (timer) clearInterval(timer);
                    toast(t.timeUp);
                    submitNow("time");
                }
            };
            tick();
            if (!sent) timer = setInterval(tick, 1000);
        }
    }

    // Confirmation box of the quiz, used instead of the browser's own box, whose buttons follow the browser language.
    // Cancel has the focus; Escape and a click outside the box also cancel.
    function askConfirm(t, text, onYes) {
        var wrap = document.createElement("div");
        wrap.className = "quiz-modal quiz-confirm-dialog";
        wrap.innerHTML =
            '<div class="quiz-modal-card" role="alertdialog" aria-modal="true" aria-describedby="quiz-confirm-text">' +
            '<p class="quiz-modal-ask" id="quiz-confirm-text">' + esc(text) + '</p>' +
            '<div class="quiz-modal-actions">' +
            '<button type="button" class="quiz-btn quiz-btn-quiet" data-cancel>' + esc(t.forgetCancel || "Cancel") + '</button>' +
            '<button type="button" class="quiz-btn" data-yes>' + esc(t.forgetRemove || "Remove") + '</button>' +
            '</div></div>';
        document.body.appendChild(english(wrap));
        var root = document.documentElement;
        var wasLocked = root.classList.contains("quiz-modal-lock");
        var previous = document.activeElement;
        root.classList.add("quiz-modal-lock");
        function onKey(e) { if (e.key === "Escape") close(); }
        function close() {
            document.removeEventListener("keydown", onKey);
            if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
            if (!wasLocked) root.classList.remove("quiz-modal-lock");
            if (previous && previous.focus) { try { previous.focus(); } catch (err) { /* ignore */ } }
        }
        document.addEventListener("keydown", onKey);
        wrap.addEventListener("click", function (e) {
            var target = e.target;
            if (target === wrap || (target.closest && target.closest("[data-cancel]"))) { close(); return; }
            if (target.closest && target.closest("[data-yes]")) { close(); onYes(); }
        });
        wrap.classList.add("is-open");
        setTimeout(function () { var cancel = wrap.querySelector("[data-cancel]"); if (cancel) cancel.focus(); }, 60);
    }

    // The thinner the time, the warmer and brighter the timer: neutral above half the time, then amber, then red
    function heat(pill, ratio) {
        var h = ratio >= 0.5 ? 0 : Math.min(1, (0.5 - ratio) / 0.5);
        var hue = h < 0.5 ? 38 : 38 - (h - 0.5) * 2 * 34;
        if (h === 0) {
            pill.style.background = ""; pill.style.borderColor = ""; pill.style.boxShadow = ""; pill.style.color = "";
            pill.style.removeProperty("--quiz-heat-line");
            return;
        }
        pill.style.background = "hsl(" + hue + ", " + Math.round(60 + h * 35) + "%, " + Math.round(98 - h * 8) + "%)";
        pill.style.borderColor = "hsl(" + hue + ", " + Math.round(55 + h * 35) + "%, " + Math.round(80 - h * 22) + "%)";
        pill.style.boxShadow = "0 0 0 " + (1 + h * 3).toFixed(1) + "px hsla(" + hue + ", 95%, 55%, " + (0.12 + h * 0.25).toFixed(2) + ")";
        pill.style.color = "hsl(" + hue + ", " + Math.round(55 + h * 25) + "%, " + Math.round(34 - h * 6) + "%)";
        pill.style.setProperty("--quiz-heat-line", "hsl(" + hue + ", 85%, " + Math.round(52 - h * 6) + "%)");
    }

    // Box shown when questions are still open: numbers can be tapped to jump to the question.
    // Every question must be answered before the answers can be sent.
    function createMissingDialog(t, onGo) {
        var wrap = document.createElement("div");
        wrap.className = "quiz-modal quiz-missing-dialog";
        wrap.innerHTML =
            '<div class="quiz-modal-card" role="dialog" aria-modal="true" aria-labelledby="quiz-missing-title">' +
            '<div class="quiz-modal-title" role="heading" aria-level="2" id="quiz-missing-title">' + esc(t.unansweredTitle) + '</div>' +
            '<p class="quiz-missing-count"></p>' +
            '<div class="quiz-missing-list"></div>' +
            '<p class="quiz-modal-note">' + esc(t.unansweredHint) + '</p>' +
            '<div class="quiz-modal-actions">' +
            '<button type="button" class="quiz-btn" data-back>' + esc(t.unansweredBack) + '</button>' +
            '</div></div>';
        document.body.appendChild(english(wrap));
        var countView = wrap.querySelector(".quiz-missing-count");
        var listView = wrap.querySelector(".quiz-missing-list");
        var cards = [];
        function close() {
            wrap.classList.remove("is-open");
            document.documentElement.classList.remove("quiz-modal-lock");
        }
        function open(missing) {
            cards = missing;
            countView.textContent = missing.length === 1 ? (t.unansweredOne || "") : (t.unansweredMany || "").replace("@count", missing.length);
            listView.innerHTML = "";
            var shown = Math.min(missing.length, 10);
            for (var i = 0; i < shown; i++) {
                var chip = document.createElement("button");
                chip.type = "button";
                chip.className = "quiz-missing-chip";
                var number = missing[i].querySelector(".quiz-num");
                chip.textContent = number ? number.textContent : String(i + 1);
                chip.setAttribute("data-index", String(i));
                listView.appendChild(chip);
            }
            if (missing.length > shown) {
                var more = document.createElement("span");
                more.className = "quiz-missing-more";
                more.textContent = (t.unansweredMore || "").replace("@count", missing.length - shown);
                listView.appendChild(more);
            }
            wrap.classList.add("is-open");
            document.documentElement.classList.add("quiz-modal-lock");
            setTimeout(function () { var back = wrap.querySelector("[data-back]"); if (back) back.focus(); }, 60);
        }
        wrap.addEventListener("click", function (e) {
            var target = e.target;
            if (target === wrap || (target.closest && target.closest("[data-back]"))) { close(); if (cards.length) onGo(cards[0]); return; }
            var chip = target.closest ? target.closest(".quiz-missing-chip") : null;
            if (chip) { close(); onGo(cards[parseInt(chip.getAttribute("data-index"), 10)]); }
        });
        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape" && wrap.classList.contains("is-open")) { close(); if (cards.length) onGo(cards[0]); }
        });
        return { open: open, close: close };
    }

    // Loading layer after submitting. It is added at once but stays invisible: CSS fades it in after 0.3 seconds,
    // so it is never seen when the result arrives quickly, and it disappears when the result page has loaded.
    // CSS is used because browsers pause JavaScript timers of a page that is being left.
    function showLoading(text) {
        if (document.querySelector(".quiz-loading")) return;
        var layer = document.createElement("div");
        layer.className = "quiz-loading";
        layer.setAttribute("role", "status");
        layer.innerHTML = '<div class="quiz-loading-card"><span class="quiz-spinner" aria-hidden="true"></span><span>' + esc(text || "") + '</span></div>';
        document.body.appendChild(english(layer));
    }

    // ---------------------------------------------------------------- start popup
    function initIntro(box) {
        var id = box.getAttribute("data-quiz-id");
        var t = readJSON(box, "data-i18n");
        document.documentElement.classList.add("quiz-modal-lock");
        // Coming back with the browser's back button while an attempt runs: continue it instead of the popup
        window.addEventListener("pageshow", function (e) {
            if (e.persisted && getCookie("yquiz_" + id)) window.location.reload();
        });
        // Last score on this quiz, remembered by this browser
        var last = storeGet(PREFIX + "last:" + id);
        var lastView = box.querySelector(".quiz-intro-last");
        if (last && Date.now() - (parseInt(last.t, 10) || 0) > LAST_DAYS * 86400000) { storeDel(PREFIX + "last:" + id); last = null; }
        if (last && lastView) {
            lastView.textContent = (t.introLast || "").replace("@score", num(last.s)).replace("@date", formatDate(last.d));
            if (hasResult(id)) { // the full result is kept for 24 hours
                var link = document.createElement("a");
                link.href = window.location.pathname + "?result";
                link.className = "quiz-intro-lastlink";
                link.textContent = t.introViewLast || "";
                lastView.appendChild(document.createTextNode(" "));
                lastView.appendChild(link);
            }
            lastView.hidden = false;
        }
        var forgetLine = box.querySelector(".quiz-intro-forget");
        var forget = box.querySelector(".quiz-forget");
        if (forgetLine && forget && (last || hasResult(id) || storeGet(PREFIX + "name"))) {
            forgetLine.hidden = false;
            forget.addEventListener("click", function () {
                askConfirm(t, t.forgetConfirm || "", function () {
                    forgetQuiz(id);
                    if (lastView) lastView.hidden = true;
                    forgetLine.hidden = true;
                });
            });
        }
        // "Reprint it" swaps the card to the reprint form, "Back" returns to the quiz information
        var mainView = box.querySelector(".quiz-intro-main");
        var reprintView = box.querySelector(".quiz-intro-reprint");
        var openReprint = box.querySelector(".quiz-intro-reprint-open");
        var backButton = box.querySelector(".quiz-intro-back");
        if (mainView && reprintView && openReprint && backButton) {
            openReprint.addEventListener("click", function () {
                mainView.hidden = true;
                reprintView.hidden = false;
                var field = reprintView.querySelector("input");
                if (field) field.focus();
            });
            backButton.addEventListener("click", function () {
                reprintView.hidden = true;
                mainView.hidden = false;
                openReprint.focus();
            });
        }
        guardRotation(t.rotateStart);
        var start = box.querySelector(".quiz-start");
        var started = false;
        var nameForm = box.querySelector(".quiz-intro-nameform");
        if (nameForm) { // a quiz with essay questions starts with the full name
            var nameInput = nameForm.querySelector(".quiz-intro-name");
            var nameError = nameForm.querySelector(".quiz-intro-nameerror");
            if (nameInput && !nameInput.value) nameInput.value = storeGet(PREFIX + "name") || "";
            nameForm.addEventListener("submit", function (e) {
                var name = nameInput ? nameInput.value.replace(/\s+/g, " ").trim() : "";
                if (started) { e.preventDefault(); return; }
                if (!name) {
                    e.preventDefault();
                    if (nameError) nameError.hidden = false;
                    if (nameInput) { nameInput.setAttribute("aria-invalid", "true"); nameInput.focus(); }
                    return;
                }
                nameInput.value = name;
                started = true;
                if (start) start.classList.add("is-busy");
                storeSet(PREFIX + "name", name);
                showLoading(t.preparing);
            });
            if (nameInput) nameInput.addEventListener("input", function () {
                if (nameError) nameError.hidden = true;
                nameInput.removeAttribute("aria-invalid");
            });
        } else if (start) start.addEventListener("click", function (e) {
            if (started) { e.preventDefault(); return; } // pressing Start again changes nothing
            started = true;
            start.classList.add("is-busy");
            showLoading(t.preparing);
        });
        var notNow = box.querySelector(".quiz-notnow");
        if (notNow) notNow.addEventListener("click", function (e) {
            var fromSite = false;
            try { fromSite = document.referrer !== "" && new URL(document.referrer).origin === window.location.origin; } catch (err) { /* old browser */ }
            if (fromSite && window.history.length > 1) { e.preventDefault(); window.history.back(); }
        });
    }

    // Device stamp: random letters made once by this browser, sent with the name so two people
    // with the same name on different devices keep their own certificates. Contains no device data.
    function getDevice() {
        var stamp = storeGet(PREFIX + "device");
        if (typeof stamp === "string" && /^[a-f0-9]{16,32}$/.test(stamp)) return stamp;
        stamp = "";
        try {
            var bytes = new Uint8Array(12);
            window.crypto.getRandomValues(bytes);
            for (var i = 0; i < bytes.length; i++) stamp += ("0" + bytes[i].toString(16)).slice(-2);
        } catch (e) {
            for (var j = 0; j < 24; j++) stamp += Math.floor(Math.random() * 16).toString(16);
        }
        storeSet(PREFIX + "device", stamp);
        return stamp;
    }

    // ---------------------------------------------------------------- essay questions
    // Words are runs of characters between spaces and line breaks; quiz.php counts them the same way
    var WORD = /[^\s\u00a0\u1680\u2000-\u200a\u2028\u2029\u202f\u205f\u3000\ufeff]+/g;
    var INSERT_MAX = 30; // more characters arriving at once than any keyboard types: pasted or inserted by a program
    function countWords(text) { return (String(text || "").match(WORD) || []).length; }
    function essayStatus(card) {
        var area = card.querySelector("textarea");
        var words = countWords(area ? area.value : "");
        var min = parseInt(card.getAttribute("data-min"), 10) || 0;
        var max = parseInt(card.getAttribute("data-max"), 10) || 300;
        return { words: words, min: min, max: max, ok: words >= Math.max(1, min) && words <= max };
    }
    function showEssayCount(card, t) {
        var status = essayStatus(card);
        var view = card.querySelector(".quiz-essay-count");
        if (view) view.textContent = (t.essayWords || "@count / @max").replace("@count", status.words).replace("@max", status.max);
        card.classList.toggle("is-over", status.words > status.max);
        card.classList.toggle("is-under", status.words > 0 && status.words < status.min);
    }
    // The field grows with the answer up to 60% of the screen, then it scrolls; while typing on a phone the height is set by initTyping
    function growEssay(area) {
        if (document.documentElement.classList.contains("quiz-typing")) return;
        area.style.height = "auto";
        var limit = Math.max(160, Math.round(visibleHeight() * 0.6));
        area.style.height = Math.min(limit, area.scrollHeight + 2) + "px";
    }
    // No pasting, dropping, copying or cutting, no text inserted at once by other programs, no help of the browser
    function guardEssay(area, t, changed) {
        // Yellow removes these attributes from the page, so they are set here: no autocorrection or capitals by the phone
        area.setAttribute("autocorrect", "off");
        area.setAttribute("autocapitalize", "off");
        area.spellcheck = false;
        var last = area.value;
        var refuse = function (e) { e.preventDefault(); toast(t.essayNoPaste); };
        area.addEventListener("paste", refuse);
        area.addEventListener("drop", refuse);
        area.addEventListener("dragover", function (e) { e.preventDefault(); });
        ["copy", "cut", "contextmenu"].forEach(function (type) { area.addEventListener(type, function (e) { e.preventDefault(); }); });
        area.addEventListener("keydown", function (e) {
            var key = String(e.key || "").toLowerCase();
            if (((e.ctrlKey || e.metaKey) && ["v", "c", "x"].indexOf(key) >= 0) || (e.shiftKey && key === "insert") || ((e.ctrlKey || e.metaKey) && key === "insert")) {
                e.preventDefault();
                if (key === "v" || key === "insert") toast(t.essayNoPaste);
            }
        });
        area.addEventListener("beforeinput", function (e) {
            var type = e.inputType || "";
            if (/^insertFrom/.test(type) || type === "insertReplacementText") { e.preventDefault(); if (/Paste|Drop|Yank/.test(type)) toast(t.essayNoPaste); }
        });
        area.addEventListener("input", function () {
            if (area.value.length - last.length > INSERT_MAX) { // put back what was there before
                var caret = Math.max(0, (area.selectionStart || 0) - (area.value.length - last.length));
                area.value = last;
                try { area.setSelectionRange(caret, caret); } catch (err) { /* ignore */ }
                toast(t.essayNoPaste);
            }
            last = area.value;
            changed();
        });
    }
    function visibleHeight() {
        return window.visualViewport ? window.visualViewport.height : window.innerHeight;
    }
    function isTouchDevice() {
        return !!(window.matchMedia && window.matchMedia("(hover: none) and (pointer: coarse)").matches);
    }
    // Typing an essay on a phone or tablet: the keyboard covers part of the screen, and Safari moves fixed parts of the page.
    // While the field has the focus, nothing on the page floats or stays fixed: the time left and the word count stand
    // right under the field, the field is as high as the space above the keyboard allows, and "Show" opens the content
    // of the box (without its media) between the question and the field.
    function initTyping(box, form, t) {
        if (!isTouchDevice()) return;
        var root = document.documentElement;
        var clock = box.querySelector(".quiz-progresstext");
        var active = null;
        Array.prototype.forEach.call(form.querySelectorAll(".quiz-essay"), function (card) {
            var group = card.closest(".quiz-group");
            var body = group ? group.querySelector(".quiz-passage-body") : null;
            if (!body) return;
            var button = document.createElement("button");
            button.type = "button";
            button.className = "quiz-essay-show";
            button.textContent = t.show || "";
            // pressing the button must not take the focus from the field, or the keyboard would close
            button.addEventListener("mousedown", function (e) { e.preventDefault(); });
            button.addEventListener("click", function () {
                var panel = card.querySelector(".quiz-essay-passage");
                if (panel) {
                    panel.parentNode.removeChild(panel);
                    button.textContent = t.show || "";
                } else {
                    panel = document.createElement("div");
                    panel.className = "quiz-essay-passage";
                    var copy = body.cloneNode(true);
                    Array.prototype.forEach.call(copy.querySelectorAll(".quiz-media, img, audio, video, iframe, canvas, svg"), function (node) {
                        if (node.parentNode) node.parentNode.removeChild(node);
                    });
                    panel.appendChild(copy);
                    card.insertBefore(panel, card.querySelector("textarea"));
                    button.textContent = t.hide || "";
                }
                var area = card.querySelector("textarea");
                if (area) { try { area.focus({ preventScroll: true }); } catch (err) { area.focus(); } }
                fitField();
            });
            card.insertBefore(button, card.querySelector(".quiz-essay-limit"));
        });
        function fitField() {
            if (!active) return;
            var area = active.querySelector("textarea");
            var meta = active.querySelector(".quiz-essay-meta");
            var panel = active.querySelector(".quiz-essay-passage");
            var view = window.visualViewport;
            var height = visibleHeight();
            var top = view ? view.offsetTop : 0;
            var free = height - (meta ? meta.offsetHeight : 0) - 24;
            if (panel) {
                panel.style.maxHeight = Math.max(80, Math.floor(free * 0.45)) + "px";
                free -= panel.offsetHeight + 8;
            }
            area.style.height = Math.max(96, Math.floor(free)) + "px";
            // the field and the line under it end just above the keyboard
            var areaRect = area.getBoundingClientRect();
            var end = (meta ? meta.getBoundingClientRect().bottom : areaRect.bottom) + 8;
            var start = (panel ? panel.getBoundingClientRect().top : areaRect.top) - 8;
            if (end > top + height) window.scrollBy(0, end - (top + height));
            else if (start < top) window.scrollBy(0, start - top);
        }
        function tick() {
            if (!active) return;
            var view = active.querySelector(".quiz-essay-time");
            if (view && clock) {
                view.hidden = false;
                view.textContent = (t.essayTimeLeft || "@time").replace("@time", clock.textContent);
            }
        }
        form.addEventListener("focusin", function (e) {
            var card = e.target && e.target.closest ? e.target.closest(".quiz-essay") : null;
            if (!card || e.target.tagName !== "TEXTAREA") return;
            if (active && active !== card) { var before = active.querySelector(".quiz-essay-time"); if (before) before.hidden = true; }
            active = card;
            root.classList.add("quiz-typing");
            tick();
            queueWide();
            setTimeout(fitField, 350); // the keyboard needs a moment to open
        });
        form.addEventListener("focusout", function (e) {
            if (!active || e.target.tagName !== "TEXTAREA") return;
            var card = active;
            setTimeout(function () {
                var now = document.activeElement;
                if (now && now.tagName === "TEXTAREA" && form.contains(now)) return; // still typing, maybe in another field
                root.classList.remove("quiz-typing");
                var time = card.querySelector(".quiz-essay-time");
                if (time) time.hidden = true;
                var area = card.querySelector("textarea");
                if (area) growEssay(area);
                active = null;
                queueWide();
            }, 150);
        });
        if (window.visualViewport) window.visualViewport.addEventListener("resize", function () { if (active) fitField(); });
        setInterval(tick, 1000);
    }
    // Keyboard users: a field or option that gets the focus under a floating box is moved into view
    function keepFocusVisible(form) {
        var keyTime = 0; // only focus moved by the keyboard (Tab, arrows), not focus set by the quiz itself
        document.addEventListener("keydown", function () { keyTime = Date.now(); }, true);
        form.addEventListener("focusin", function (e) {
            if (Date.now() - keyTime > 500) return;
            var target = e.target;
            var group = target && target.closest ? target.closest(".quiz-group.is-floating") : null;
            if (!group || target.closest(".quiz-passage")) return;
            (window.requestAnimationFrame || setTimeout)(function () {
                var cover = coverBottom(target);
                var rect = target.getBoundingClientRect();
                if (rect.top < cover + 8) window.scrollBy(0, rect.top - cover - 16);
            });
        });
    }
    // Lowest point of what covers the top of the screen at the place of an element: the timer, and the floating
    // box of the element's group
    function coverBottom(element) {
        var bottom = 0;
        var pill = document.querySelector(".quiz-container .quiz-progress");
        if (pill && pill.getClientRects().length) bottom = Math.max(bottom, pill.getBoundingClientRect().bottom);
        var banner = document.querySelector(".quiz-schedule-banner");
        if (banner && banner.getClientRects().length) bottom = Math.max(bottom, banner.getBoundingClientRect().bottom);
        var group = element && element.closest ? element.closest(".quiz-group.is-floating") : null;
        var passage = group ? group.querySelector(".quiz-passage") : null;
        if (passage && !document.documentElement.classList.contains("quiz-typing")) bottom = Math.max(bottom, floatTop() + passage.offsetHeight);
        return bottom;
    }

    // ---------------------------------------------------------------- phone held sideways
    // On a phone held sideways the screen is too low for the quiz: a notice covers the page until the phone is upright.
    // Phones and tablets only; a screen of 500 px height or more (a tablet, an open foldable phone) is never blocked.
    // The position is read from the screen itself: an open keyboard makes the page wider than high, not the phone.
    var ROTATE_MIN_HEIGHT = 500;
    var rotateView = null;
    function isLandscape() {
        var type = window.screen && window.screen.orientation ? window.screen.orientation.type : "";
        if (typeof type === "string" && type !== "") return type.indexOf("landscape") === 0;
        if (typeof window.orientation === "number") return Math.abs(window.orientation) === 90; // older Safari
        return window.innerWidth > window.innerHeight;
    }
    function updateRotation() {
        if (!rotateView) return;
        var active = document.activeElement;
        if (active && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName)) return; // keyboard open: wait until it closes
        var blocked = isLandscape() && window.innerHeight < ROTATE_MIN_HEIGHT;
        rotateView.hidden = !blocked;
        document.documentElement.classList.toggle("quiz-rotate-lock", blocked);
    }
    function guardRotation(text) {
        if (!isTouchDevice() || !text) return;
        if (!rotateView) {
            rotateView = document.createElement("div");
            rotateView.className = "quiz-rotate";
            rotateView.setAttribute("role", "alert");
            rotateView.hidden = true;
            rotateView.innerHTML = '<p class="quiz-rotate-text"></p>';
            document.body.appendChild(english(rotateView));
            var later = function () { setTimeout(updateRotation, 300); };
            window.addEventListener("resize", updateRotation);
            window.addEventListener("orientationchange", later);
            if (window.screen && window.screen.orientation && window.screen.orientation.addEventListener) window.screen.orientation.addEventListener("change", later);
            document.addEventListener("focusout", later);
        }
        rotateView.querySelector(".quiz-rotate-text").textContent = text;
        updateRotation();
    }

    // ---------------------------------------------------------------- theme headers on quiz pages
    // A header of the theme that stays on the screen while scrolling would cover the timer and the floating boxes.
    // On quiz pages it scrolls away with the page instead; headers that do not float are left alone.
    var headersReleased = false;
    function releaseHeaders() {
        if (headersReleased || !document.body) return;
        headersReleased = true;
        var skip = ".quiz-container, .quiz-modal, .quiz-toast, .quiz-loading, .quiz-schedule-banner, .quiz-rotate, .quiz-print-name";
        var candidates = [];
        var walk = function (node, depth) {
            for (var child = node.firstElementChild; child; child = child.nextElementSibling) {
                var name = (child.id || "") + " " + (typeof child.className === "string" ? child.className : "");
                if (child.matches(skip) || /yellow/i.test(name) || /^(SCRIPT|STYLE|LINK|META|NOSCRIPT|TEMPLATE)$/.test(child.tagName)) continue;
                candidates.push(child);
                if (depth < 4) walk(child, depth + 1);
            }
        };
        walk(document.body, 0);
        var check = function () {
            var width = document.documentElement.clientWidth, height = window.innerHeight;
            candidates.forEach(function (element) {
                if (element.getAttribute("data-quiz-released")) return;
                var style = window.getComputedStyle(element);
                if (style.position !== "fixed" && style.position !== "sticky") return;
                if (style.display === "none" || style.visibility === "hidden" || parseFloat(style.opacity) === 0) return;
                var rect = element.getBoundingClientRect();
                if (rect.top > 64 || rect.height <= 0 || rect.bottom > height * 0.35 || rect.width < width * 0.5) return;
                // a fixed header keeps its place at the top of the page, a sticky one its place in the page
                element.style.setProperty("position", style.position === "fixed" ? "absolute" : "relative", "important");
                element.setAttribute("data-quiz-released", "1");
                queueWide();
            });
        };
        check();
        var queued = false; // themes often make a header fixed only after scrolling
        window.addEventListener("scroll", function () {
            if (queued) return;
            queued = true;
            setTimeout(function () { queued = false; check(); }, 250);
        }, { passive: true });
    }

    // ---------------------------------------------------------------- media, diagrams, charts
    var scripts = {};
    function loadScript(url, ready) {
        if (!url) return;
        if (scripts[url]) { scripts[url].push(ready); return; }
        scripts[url] = [ready];
        var tag = document.createElement("script");
        tag.src = url;
        tag.async = true;
        tag.onload = function () { var list = scripts[url]; scripts[url] = { push: function (fn) { fn(); } }; list.forEach(function (fn) { fn(); }); };
        tag.onerror = function () { if (window.console) console.warn("Quiz: could not load " + url); };
        document.head.appendChild(tag);
    }
    // ---- diagrams and charts get more room than the quiz column, centred on the screen:
    // 90% of the screen width (at most 1400 px), 97.5% on phones (600 px or less).
    // Only when the content column of the theme is centred on the screen, so a block never covers a sidebar;
    // a block that a theme would cut off keeps the width of the quiz.
    // A diagram is never drawn smaller than 60% of its own size; a narrower screen scrolls it inside its block.
    var WIDE_MAX = 1400;
    var WIDE_PHONE = 600;
    var WIDE_CENTRE = 0.05; // allowed distance of the column centre from the screen centre: 5% of the column width
    var WIDE_CENTRE_MIN = 24; // ... but at least 24 px
    var DIAGRAM_FLOOR = 0.6;
    var wideBlocks = [];
    var wideQueued = false;
    var wideListening = false;
    function unwide(block) {
        block.classList.remove("is-wide");
        block.style.removeProperty("--quiz-wide-width");
        block.style.removeProperty("--quiz-wide-shift");
        block.removeAttribute("data-wide-shift");
    }
    function floorDiagram(block) {
        var svg = block.querySelector("svg");
        var own = svg ? parseFloat(svg.style.maxWidth) : 0;
        if (own > 0) svg.style.minWidth = Math.round(own * DIAGRAM_FLOOR) + "px";
    }
    // true when a wrapper of the theme (not html or body, which clip at the screen edge) cuts the block off
    function isClipped(block) {
        var rect = block.getBoundingClientRect();
        for (var node = block.parentElement; node && node !== document.body && node !== document.documentElement; node = node.parentElement) {
            var style = window.getComputedStyle(node);
            if (style.overflowX === "visible" && style.overflow === "visible") continue;
            var edge = node.getBoundingClientRect();
            if (rect.left < edge.left - 1 || rect.right > edge.right + 1) return true;
        }
        return false;
    }
    // true when the content column of the theme (the parent of the quiz) is centred on the screen
    function isCentred(block, screen) {
        var quiz = block.closest(".quiz-container");
        var column = quiz && quiz.parentElement ? quiz.parentElement.getBoundingClientRect() : null;
        return !column || Math.abs(column.left + column.width / 2 - screen / 2) <= Math.max(WIDE_CENTRE_MIN, column.width * WIDE_CENTRE);
    }
    // ---- floating boxes ([box] ... [split] ... [/box]): the box reaches beyond the quiz column on each side when the
    // column is centred: 2rem on laptops, TVs and projectors (mouse or touchpad, 1024 px or wider), 1.5rem on tablets.
    // It always keeps a distance to the screen edge (12 px on phones, 4 px on wider screens), so the page never scrolls
    // sideways; a box that a theme would cut off keeps the width of the quiz.
    // The box floats (stays under the timer) while its questions are on the screen and stops at the end of its group.
    // It is at most 45% of the visible screen high (35% on phones); a box with a video at most 60%. A longer content scrolls
    // inside the box, which shows one scroll bar, "End" at the end and a short glow when the student keeps scrolling at
    // the end. Its videos are 16:9 and at most as wide as the box; where the box with a video would be higher than its
    // limit, the videos become narrower (centred) until it fits. Hide / Show hides the content of a box that is higher than
    // 30% of the screen, and of every box on phones.
    // Nothing floats where a wrapper of the theme would stop it, and nothing floats while an essay is typed on a touch device.
    var PASSAGE_EDGE_PHONE = 12;
    var PASSAGE_EDGE = 4;
    var PASSAGE_REACH = 1.5; // rem
    var PASSAGE_REACH_WIDE = 2; // rem
    var FLOAT_SHARE = 0.45;
    var FLOAT_SHARE_PHONE = 0.35; // phones: a box without video
    var VIDEO_MIN = 200; // px, the narrowest video of a floating box
    var HIDE_FROM = 0.3; // Hide / Show for a floating box higher than this share of the screen
    // the content of a floating box is as wide as the box: rules of the theme for div elements (auto margins, align-self)
    // would make it only as wide as its text in the column of the box; inline !important wins over every rule of the theme
    var BODY_LOCK = { "align-self": "stretch", width: "auto", "max-width": "none", "margin-left": "0px", "margin-right": "0px" };
    var passages = [];
    // a box is laid out again when its content changes its size later (a font or a picture that loads, a player that appears)
    var contentWatch = window.ResizeObserver ? new window.ResizeObserver(function () { queueWide(); }) : null;
    var floatTopValue = 8;
    function floatTop() { return floatTopValue; }
    function isWideScreen(screen) {
        return screen >= 1024 && !!(window.matchMedia && window.matchMedia("(hover: hover) and (pointer: fine)").matches);
    }
    // a wrapper that scrolls or clips becomes the frame of a floating box, which then never moves with the page
    function canFloat(element) {
        if (!(window.CSS && window.CSS.supports && window.CSS.supports("position", "sticky"))) return false;
        var open = function (style) { return /^(visible|clip)$/.test(style.overflowX) && /^(visible|clip)$/.test(style.overflowY); };
        for (var node = element.parentElement; node && node !== document.body && node !== document.documentElement; node = node.parentElement) {
            if (!open(window.getComputedStyle(node))) return false;
        }
        // the overflow of body belongs to the window, unless html has its own
        return open(window.getComputedStyle(document.documentElement)) || open(window.getComputedStyle(document.body));
    }
    // Width (px) of the videos of a floating box so that the box with its videos stays within its height limit,
    // 0 when they can be as wide as the box. What stands above the first video (a heading, a short text) stays visible
    // with it; a long text above it scrolls away. Measured without height limit and scroll bar (class is-measuring).
    function videoWidth(box, limit) {
        var body = box.quizBody;
        var videos = body.querySelectorAll(".quiz-player, .quiz-movie");
        if (!videos.length || box.classList.contains("is-collapsed")) return 0;
        var style = window.getComputedStyle(box);
        var room = limit - (parseFloat(style.paddingTop) || 0) - (parseFloat(style.paddingBottom) || 0) -
            (parseFloat(style.borderTopWidth) || 0) - (parseFloat(style.borderBottomWidth) || 0);
        var above = videos[0].getBoundingClientRect().top - (body.getBoundingClientRect().top - body.scrollTop);
        var width = Math.max(VIDEO_MIN, Math.floor((room - (above <= room / 2 ? above : 0)) * 16 / 9));
        return width < body.clientWidth ? width : 0;
    }
    // Rail, "End" and glow of a floating box whose content scrolls
    function updateScroll(box) {
        var body = box.quizBody;
        // a scroll bar of the system that stays from an earlier layout although the content fits now (a picture has loaded,
        // the box has grown) is removed: reading scrollHeight lays the box out once without it
        if (body.offsetWidth - body.clientWidth > 0 && body.scrollHeight <= body.clientHeight) {
            body.style.overflowY = "hidden";
            body.style.overflowY = body.scrollHeight < 0 ? "hidden" : "";
        }
        var floating = !!box.parentElement && box.parentElement.classList.contains("is-floating");
        var scrolls = floating && !box.classList.contains("is-collapsed") && body.scrollHeight > body.clientHeight + 1;
        box.classList.toggle("is-scrollable", scrolls);
        box.classList.toggle("is-end", scrolls && body.scrollTop + body.clientHeight >= body.scrollHeight - 2);
        // one scroll bar only: where the system draws its scroll bar over the content (phones, tablets, Mac), the quiz
        // shows its rail all the time and hides the bar of the system (quiz.css); elsewhere the bar of the system stays
        var rail = scrolls && body.offsetWidth - body.clientWidth < 1;
        box.classList.toggle("has-rail", rail);
        if (!rail) return;
        var thumb = Math.max(24, Math.round(body.clientHeight * body.clientHeight / body.scrollHeight));
        box.quizRail.style.top = body.offsetTop + "px";
        box.quizRail.style.height = body.clientHeight + "px";
        box.quizThumb.style.height = thumb + "px";
        box.quizThumb.style.top = Math.round((body.clientHeight - thumb) * body.scrollTop / Math.max(1, body.scrollHeight - body.clientHeight)) + "px";
    }
    // Scrolling on although the content is at its end (or at its start): the edge of the box glows for a moment
    function bump(box, edge) {
        box.classList.remove("is-bump-start", "is-bump-end");
        box.classList.add("is-bump-" + edge);
        clearTimeout(box.quizBump);
        box.quizBump = setTimeout(function () { box.classList.remove("is-bump-" + edge); }, 700);
    }
    // Parts the quiz adds to a box once: Hide / Show, rail, "End", glows and the line of a hidden box with its sound or video
    function setupBox(box, t) {
        var body = box.querySelector(".quiz-passage-body");
        box.quizBody = body;
        box.quizTexts = t;
        // embedded pages that the quiz cannot pause stay visible when the box is hidden
        Array.prototype.forEach.call(body.children, function (child) {
            if (child.matches(".quiz-frame:not(.quiz-player)")) child.classList.add("quiz-keep");
        });
        box.quizHideable = Array.prototype.some.call(body.children, function (child) {
            return !child.classList.contains("quiz-keep") && !child.querySelector("audio");
        });
        if (contentWatch) Array.prototype.forEach.call(body.children, function (child) { contentWatch.observe(child); });
        var attach = function (tag, className) { return box.appendChild(make(tag, className)); };
        var button = attach("button", "quiz-passage-toggle");
        button.type = "button";
        button.hidden = true;
        button.addEventListener("click", function () {
            var hide = !box.classList.contains("is-collapsed");
            box.classList.toggle("is-collapsed", hide);
            showToggle(box);
            updateStrip(box);
            queueWide();
        });
        box.quizToggle = button;
        box.quizRail = attach("span", "quiz-passage-rail");
        box.quizThumb = box.quizRail.appendChild(make("span", "quiz-passage-thumb"));
        attach("span", "quiz-passage-glow quiz-glow-start");
        attach("span", "quiz-passage-glow quiz-glow-end");
        attach("span", "quiz-passage-end").textContent = t.boxEnd || "";
        var strip = attach("div", "quiz-passage-strip");
        strip.innerHTML = '<button type="button" class="quiz-strip-play"></button><span class="quiz-strip-dot"></span><span class="quiz-strip-status"></span>';
        strip.querySelector(".quiz-strip-play").addEventListener("click", function () {
            var medium = boxMedium(box);
            if (medium) { if (medium.playing) pauseMedium(medium); else playMedium(medium); }
        });
        box.quizStrip = strip;
        var atEnd = function () { return body.scrollTop + body.clientHeight >= body.scrollHeight - 2; };
        var atStart = function () { return body.scrollTop <= 1; };
        var edge = function (down) {
            if (!box.classList.contains("is-scrollable")) return;
            if (down && atEnd()) bump(box, "end");
            else if (!down && atStart()) bump(box, "start");
        };
        body.addEventListener("scroll", function () { updateScroll(box); }, { passive: true });
        body.addEventListener("wheel", function (e) { if (e.deltaY) edge(e.deltaY > 0); }, { passive: true });
        var touchY = null;
        body.addEventListener("touchstart", function (e) { touchY = e.touches.length ? e.touches[0].clientY : null; }, { passive: true });
        body.addEventListener("touchmove", function (e) {
            if (touchY === null || !e.touches.length) return;
            var moved = touchY - e.touches[0].clientY;
            if (Math.abs(moved) > 12) edge(moved > 0);
        }, { passive: true });
        body.addEventListener("keydown", function (e) {
            if (/^(ArrowDown|PageDown|End| )$/.test(e.key)) edge(true);
            else if (/^(ArrowUp|PageUp|Home)$/.test(e.key)) edge(false);
        });
    }
    function showToggle(box) {
        var t = box.quizTexts;
        var hidden = box.classList.contains("is-collapsed");
        box.quizToggle.textContent = hidden ? t.show || "" : t.hide || "";
        box.quizToggle.setAttribute("aria-expanded", hidden ? "false" : "true");
    }
    function layoutPassages(screen) {
        if (!passages.length) return;
        var rem = parseFloat(window.getComputedStyle(document.documentElement).fontSize) || 16;
        var phone = screen <= WIDE_PHONE;
        var edge = phone ? PASSAGE_EDGE_PHONE : PASSAGE_EDGE;
        var reach = rem * (isWideScreen(screen) ? PASSAGE_REACH_WIDE : PASSAGE_REACH);
        var typing = document.documentElement.classList.contains("quiz-typing");
        var view = visibleHeight();
        var share = Math.floor(view * (phone ? FLOAT_SHARE_PHONE : FLOAT_SHARE));
        var shareVideo = Math.floor(view * 0.6);
        // the box floats under the timer and under the closing notice of the quiz
        var top = 8;
        var pill = document.querySelector(".quiz-container .quiz-progress");
        if (pill && pill.getClientRects().length) top = Math.max(top, Math.ceil(pill.getBoundingClientRect().bottom) + 8);
        var banner = document.querySelector(".quiz-schedule-banner");
        if (banner && banner.getClientRects().length) top = Math.max(top, Math.ceil(banner.getBoundingClientRect().bottom) + 8);
        floatTopValue = top;
        // read every position first, then write
        var plans = passages.map(function (box) {
            var group = box.parentElement;
            if (!group || !box.getClientRects().length) return null;
            if (!isCentred(box, screen)) return 0;
            var rect = group.getBoundingClientRect();
            return Math.max(0, Math.min(reach, Math.floor(Math.min(rect.left, screen - rect.right) - edge)));
        });
        var floats = passages.map(function (box) { return !typing && box.getClientRects().length > 0 && canFloat(box); });
        passages.forEach(function (box, i) {
            Array.prototype.forEach.call(box.querySelectorAll(".quiz-diagram"), floorDiagram);
            if (plans[i] !== null) box.style.setProperty("--quiz-passage-out", plans[i] + "px");
            box.style.setProperty("--quiz-float-top", top + "px");
            if (box.parentElement) box.parentElement.classList.toggle("is-floating", floats[i]);
            Object.keys(BODY_LOCK).forEach(function (name) {
                if (floats[i]) box.quizBody.style.setProperty(name, BODY_LOCK[name], "important");
                else box.quizBody.style.removeProperty(name);
            });
        });
        passages.forEach(function (box, i) { if (plans[i] && isClipped(box)) box.style.setProperty("--quiz-passage-out", "0px"); });
        // heights and videos: a floating box with a video is measured with its final width, without the height limit and
        // without a scroll bar, which would make the videos narrower; the place the student has scrolled to is kept
        var tops = passages.map(function (box) { return box.quizBody.scrollTop; });
        var measured = passages.map(function (box, i) { return floats[i] && !!box.quizBody.querySelector(".quiz-player, .quiz-movie"); });
        passages.forEach(function (box, i) { if (measured[i]) box.classList.add("is-measuring"); });
        var widths = passages.map(function (box, i) { return measured[i] ? videoWidth(box, shareVideo) : 0; });
        passages.forEach(function (box, i) { if (measured[i]) box.classList.remove("is-measuring"); });
        passages.forEach(function (box, i) {
            if (widths[i]) box.style.setProperty("--quiz-video-max", widths[i] + "px");
            else box.style.removeProperty("--quiz-video-max");
            box.style.setProperty("--quiz-float-max", (measured[i] ? shareVideo : share) + "px");
            if (measured[i] && box.quizBody.scrollTop !== tops[i]) box.quizBody.scrollTop = tops[i];
        });
        var high = passages.map(function (box, i) { return floats[i] && box.getBoundingClientRect().height > view * HIDE_FROM; });
        passages.forEach(function (box, i) {
            var hidden = box.classList.contains("is-collapsed");
            var offer = floats[i] && box.quizHideable && (phone || hidden || high[i]);
            if (!offer && hidden) { box.classList.remove("is-collapsed"); updateStrip(box); }
            box.quizToggle.hidden = !offer;
            showToggle(box);
            updateScroll(box);
        });
    }
    function layoutWide() {
        wideQueued = false;
        var screen = document.documentElement.clientWidth;
        layoutPassages(screen);
        var target = Math.floor(screen <= WIDE_PHONE ? screen * 0.975 : Math.min(screen * 0.9, WIDE_MAX));
        // read every position first, then write, so the page is laid out only once
        var plans = wideBlocks.map(function (block) {
            var parent = block.parentElement;
            if (!parent || !block.getClientRects().length) return null;
            if (!isCentred(block, screen)) return { wide: false };
            var style = window.getComputedStyle(parent);
            var inner = parent.clientWidth - (parseFloat(style.paddingLeft) || 0) - (parseFloat(style.paddingRight) || 0);
            var shift = parseFloat(block.getAttribute("data-wide-shift")) || 0;
            var left = block.getBoundingClientRect().left - shift;
            return { wide: target > inner + 1, shift: Math.round((screen - target) / 2 - left) };
        });
        wideBlocks.forEach(function (block, i) {
            floorDiagram(block);
            var plan = plans[i];
            if (!plan) return;
            if (!plan.wide) { unwide(block); return; }
            block.classList.add("is-wide");
            block.style.setProperty("--quiz-wide-width", target + "px");
            block.style.setProperty("--quiz-wide-shift", plan.shift + "px");
            block.setAttribute("data-wide-shift", String(plan.shift));
        });
        wideBlocks.forEach(function (block) { if (block.classList.contains("is-wide") && isClipped(block)) unwide(block); });
    }
    function queueWide() {
        if (wideQueued) return;
        wideQueued = true;
        (window.requestAnimationFrame || function (fn) { return setTimeout(fn, 16); })(layoutWide);
    }
    function widen(box) {
        // diagrams and charts in a box stay inside the box
        var blocks = Array.prototype.filter.call(box.querySelectorAll(".quiz-diagram, .quiz-chart"), function (block) { return !block.closest(".quiz-passage"); });
        var boxes = box.querySelectorAll(".quiz-passage");
        if (!blocks.length && !boxes.length) return;
        blocks.forEach(function (block) { if (wideBlocks.indexOf(block) < 0) wideBlocks.push(block); });
        Array.prototype.forEach.call(boxes, function (passage) {
            if (passages.indexOf(passage) >= 0) return;
            passages.push(passage);
            setupBox(passage, readJSON(passage.closest(".quiz-container") || passage, "data-i18n"));
        });
        if (!wideListening) {
            wideListening = true;
            window.addEventListener("resize", queueWide);
            window.addEventListener("orientationchange", queueWide);
            window.addEventListener("load", queueWide);
            if (window.visualViewport) window.visualViewport.addEventListener("resize", queueWide);
        }
        layoutWide();
    }
    // ---- sound and video: only one plays at a time, the others pause (and continue later where they stopped).
    // A medium in a box pauses when its group (box and questions) has left the screen, a medium in a question when its
    // question has left the screen; other media play on while the student answers the questions below. A hidden box shows a line
    // with Play / Pause and the time of its sound or video. Players of the browser are paused directly, YouTube and
    // Vimeo players through their own messages; embedded pages of other sites cannot be paused and are left alone.
    var media = [];
    var mediaWatch = null;
    var PLAYER_ORIGINS = { youtube: /^https:\/\/(www\.)?youtube(-nocookie)?\.com$/, vimeo: /^https:\/\/player\.vimeo\.com$/ };
    function formatClock(seconds) {
        seconds = Math.max(0, Math.floor(seconds || 0));
        return Math.floor(seconds / 60) + ":" + ("0" + seconds % 60).slice(-2);
    }
    function findMedium(node) {
        for (var i = 0; i < media.length; i++) if (media[i].node === node) return media[i];
        return null;
    }
    function sendPlayer(medium, message) {
        var frame = medium.node;
        if (!frame.contentWindow) return;
        // the message only says "pause" or "play"; the player may answer from www.youtube.com or youtube-nocookie.com
        try { frame.contentWindow.postMessage(JSON.stringify(message), "*"); } catch (e) { /* not loaded yet */ }
    }
    function pauseMedium(medium) {
        if (medium.kind === "element") { if (!medium.node.paused) medium.node.pause(); return; }
        if (medium.kind === "youtube") sendPlayer(medium, { event: "command", func: "pauseVideo", args: [] });
        else sendPlayer(medium, { method: "pause" });
        medium.playing = false;
        updateStrip(medium.box);
    }
    function playMedium(medium) {
        if (medium.kind === "element") {
            var promise = medium.node.play();
            if (promise && promise.catch) promise.catch(function () {});
            return;
        }
        if (medium.kind === "youtube") sendPlayer(medium, { event: "command", func: "playVideo", args: [] });
        else sendPlayer(medium, { method: "play" });
        startMedium(medium);
    }
    // A medium starts: the others pause, a box remembers its last medium for its line
    function startMedium(medium) {
        medium.playing = true;
        if (medium.box) medium.box.quizMedium = medium;
        media.forEach(function (other) { if (other !== medium && other.playing) pauseMedium(other); });
        updateStrip(medium.box);
    }
    function boxMedium(box) {
        if (box.quizMedium) return box.quizMedium;
        for (var i = 0; i < media.length; i++) if (media[i].box === box) return media[i];
        return null;
    }
    // The line of a hidden box: Play / Pause, a dot while it plays, "Playing 1:23 / 4:05"
    function updateStrip(box) {
        if (!box || !box.quizStrip) return;
        var medium = boxMedium(box);
        var shown = box.classList.contains("is-collapsed") && !!medium;
        box.classList.toggle("has-strip", shown);
        if (!shown) return;
        var t = box.quizTexts;
        if (medium.kind === "element") {
            medium.time = medium.node.currentTime;
            medium.duration = isFinite(medium.node.duration) ? medium.node.duration : 0;
        }
        var time = medium.time >= 0 ? formatClock(medium.time) + (medium.duration > 0 ? " / " + formatClock(medium.duration) : "") : "";
        var button = box.quizStrip.querySelector(".quiz-strip-play");
        button.textContent = medium.playing ? "\u275A\u275A" : "\u25B6";
        button.setAttribute("aria-label", medium.playing ? t.mediaPause || "" : t.mediaPlay || "");
        box.quizStrip.classList.toggle("is-playing", medium.playing);
        box.quizStrip.querySelector(".quiz-strip-status").textContent = ((medium.playing ? t.mediaPlaying : t.mediaPaused) || "@time").replace("@time", time).trim();
    }
    // Players of YouTube and Vimeo tell their state when asked; messages of other pages are ignored
    function listenPlayer(medium) {
        var tries = 0;
        var ask = function () {
            if (medium.ready || tries++ > 20) return;
            if (medium.kind === "youtube") sendPlayer(medium, { event: "listening", id: media.indexOf(medium), channel: "widget" });
            else ["play", "pause", "ended", "timeupdate"].forEach(function (name) { sendPlayer(medium, { method: "addEventListener", value: name }); });
            setTimeout(ask, 500);
        };
        medium.node.addEventListener("load", function () { tries = 0; medium.ready = false; ask(); });
        ask();
    }
    function onPlayerMessage(e) {
        var medium = null;
        for (var i = 0; i < media.length; i++) if (media[i].kind !== "element" && media[i].node.contentWindow === e.source) medium = media[i];
        if (!medium || !PLAYER_ORIGINS[medium.kind].test(e.origin)) return;
        var data = e.data;
        if (typeof data === "string") { try { data = JSON.parse(data); } catch (err) { return; } }
        if (!data || typeof data !== "object") return;
        medium.ready = true;
        var state = null;
        if (medium.kind === "youtube") {
            var info = data.info && typeof data.info === "object" ? data.info : {};
            if (data.event === "onStateChange" && typeof data.info === "number") state = data.info;
            if (typeof info.playerState === "number") state = info.playerState;
            if (typeof info.currentTime === "number") medium.time = info.currentTime;
            if (typeof info.duration === "number") medium.duration = info.duration;
            if (state === 1) { if (!medium.playing) startMedium(medium); }
            else if (state === 0 || state === 2) medium.playing = false;
        } else {
            if (data.event === "ready") ["play", "pause", "ended", "timeupdate"].forEach(function (name) { sendPlayer(medium, { method: "addEventListener", value: name }); });
            var detail = data.data && typeof data.data === "object" ? data.data : {};
            if (typeof detail.seconds === "number") medium.time = detail.seconds;
            if (typeof detail.duration === "number") medium.duration = detail.duration;
            if (data.event === "play") { if (!medium.playing) startMedium(medium); }
            else if (data.event === "pause" || data.event === "ended") medium.playing = false;
        }
        updateStrip(medium.box);
    }
    function initPlayers(container) {
        var nodes = container.querySelectorAll("audio, video, .quiz-player iframe[data-player]");
        if (!nodes.length) return;
        if (!media.length) {
            window.addEventListener("message", onPlayerMessage);
            // players of the browser
            document.addEventListener("play", function (e) { var medium = findMedium(e.target); if (medium) startMedium(medium); }, true);
            ["pause", "ended"].forEach(function (name) {
                document.addEventListener(name, function (e) {
                    var medium = findMedium(e.target);
                    if (medium) { medium.playing = false; updateStrip(medium.box); }
                }, true);
            });
            ["timeupdate", "loadedmetadata", "durationchange"].forEach(function (name) {
                document.addEventListener(name, function (e) {
                    var medium = findMedium(e.target);
                    if (!medium) return;
                    medium.time = e.target.currentTime;
                    medium.duration = isFinite(e.target.duration) ? e.target.duration : 0;
                    if (medium.box && medium.box.classList.contains("is-collapsed")) updateStrip(medium.box);
                }, true);
            });
            // a click into a YouTube or Vimeo player that does not answer moves the focus into it: it is taken as playing
            window.addEventListener("blur", function () {
                setTimeout(function () {
                    var medium = findMedium(document.activeElement);
                    if (medium && medium.kind !== "element" && !medium.ready) startMedium(medium);
                }, 0);
            });
            if (window.IntersectionObserver) {
                mediaWatch = new window.IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) return;
                        media.forEach(function (medium) { if (medium.place === entry.target && medium.playing) pauseMedium(medium); });
                    });
                });
            }
        }
        Array.prototype.forEach.call(nodes, function (node) {
            if (findMedium(node)) return;
            var kind = node.tagName === "IFRAME" ? node.getAttribute("data-player") : "element";
            if (kind !== "element" && !PLAYER_ORIGINS[kind]) return;
            // YouTube answers only a page that it knows by its address (origin)
            if (kind === "youtube" && /^https?:/.test(window.location.protocol) && !/[?&]origin=/.test(node.src)) {
                node.src += (node.src.indexOf("?") < 0 ? "?" : "&") + "origin=" + encodeURIComponent(window.location.protocol + "//" + window.location.host);
            }
            var place = node.closest(".quiz-group") || node.closest(".quiz-q");
            var medium = { node: node, kind: kind, playing: kind === "element" ? !node.paused : false, time: -1, duration: 0,
                box: node.closest(".quiz-passage"), place: place, ready: false };
            if (kind === "element") medium.time = 0;
            media.push(medium);
            if (kind !== "element") listenPlayer(medium);
            if (mediaWatch && place) mediaWatch.observe(place);
        });
    }
    var mediaGuard = false;
    function initMedia(box) {
        widen(box);
        initPlayers(box);
        // Mermaid diagrams: drawn when the page opens, with the library of the site if there is one
        var diagrams = box.querySelectorAll(".quiz-mermaid:not([data-processed])");
        if (diagrams.length) {
            var draw = function (ownLibrary) {
                var mermaid = window.mermaid;
                if (!mermaid) return;
                try {
                    if (ownLibrary && mermaid.initialize) mermaid.initialize({ startOnLoad: false, securityLevel: "strict" });
                    var nodes = Array.prototype.filter.call(diagrams, function (node) { return !node.getAttribute("data-processed"); });
                    var drawn = mermaid.run ? mermaid.run({ nodes: nodes }) : (mermaid.init ? mermaid.init(undefined, nodes) : null);
                    if (drawn && typeof drawn.then === "function") drawn.then(queueWide, queueWide);
                    else queueWide();
                } catch (e) { if (window.console) console.warn("Quiz: diagram", e); }
            };
            if (window.mermaid) draw(false);
            else loadScript(box.getAttribute("data-mermaid-url"), function () { draw(true); });
        }
        // Chart.js charts: the configuration is JSON or a JavaScript object, as on the material pages
        var charts = box.querySelectorAll(".quiz-chart canvas[data-config]");
        if (charts.length) {
            var plot = function () {
                if (!window.Chart) return;
                Array.prototype.forEach.call(charts, function (canvas) {
                    if (canvas.getAttribute("data-drawn")) return;
                    canvas.setAttribute("data-drawn", "1");
                    var text = canvas.getAttribute("data-config") || "";
                    var config = null;
                    try { config = JSON.parse(text); } catch (e) {
                        try { config = (new Function("return (" + text + "\n);"))(); } catch (err) { config = null; }
                    }
                    if (config && typeof config === "object") {
                        // the chart fills its block (sized by quiz.css), unless the author decided otherwise
                        config.options = config.options && typeof config.options === "object" ? config.options : {};
                        if (config.options.maintainAspectRatio === undefined) config.options.maintainAspectRatio = false;
                        try { new window.Chart(canvas, config); } catch (e) { if (window.console) console.warn("Quiz: chart", e); }
                    }
                });
            };
            if (window.Chart) plot();
            else loadScript(box.getAttribute("data-chart-url"), plot);
        }
        // Full screen and picture-in-picture are not allowed: the timer must stay visible
        if (mediaGuard) return;
        mediaGuard = true;
        // only for players of the quiz; other parts of the page are left alone
        var leaveFullscreen = function () {
            var element = document.fullscreenElement || document.webkitFullscreenElement;
            if (!element || !element.closest || !element.closest(".quiz-container")) return;
            var exit = document.exitFullscreen || document.webkitExitFullscreen;
            if (exit) { try { var result = exit.call(document); if (result && result.catch) result.catch(function () {}); } catch (e) { /* ignore */ } }
        };
        document.addEventListener("fullscreenchange", leaveFullscreen);
        document.addEventListener("webkitfullscreenchange", leaveFullscreen);
        // Yellow removes these attributes from the page. Without download, speed, casting and picture-in-picture the
        // players have no menu (three dots) in Chrome and Edge; videos play inside the page (iPhone), without full screen
        Array.prototype.forEach.call(document.querySelectorAll(".quiz-container audio"), function (audio) {
            audio.setAttribute("controlslist", "nodownload noplaybackrate noremoteplayback");
            audio.setAttribute("disableremoteplayback", "");
        });
        Array.prototype.forEach.call(document.querySelectorAll(".quiz-container video"), function (video) {
            video.setAttribute("playsinline", "");
            video.setAttribute("webkit-playsinline", "");
            video.setAttribute("controlslist", "nodownload noplaybackrate noremoteplayback nofullscreen");
            video.setAttribute("disableremoteplayback", "");
            video.disablePictureInPicture = true;
            video.addEventListener("webkitbeginfullscreen", function () { try { video.webkitExitFullscreen(); } catch (e) { /* ignore */ } });
            video.addEventListener("enterpictureinpicture", function () {
                if (document.exitPictureInPicture) document.exitPictureInPicture().catch(function () {});
            });
        });
    }

    // ---------------------------------------------------------------- result page
    function initResult(box) {
        var id = box.getAttribute("data-quiz-id");
        var data = readJSON(box, "data-result");
        var t = readJSON(box, "data-i18n");
        // In the editor of Yellow the result was rendered for a POST: keep it in this browser like the server would,
        // and switch the address to ?result, so a refresh shows the same result instead of sending the answers again
        var token = box.getAttribute("data-result-token");
        if (token) {
            setCookie("yquizr_" + id, token, 86400, window.location.pathname);
            if (window.history && history.replaceState) history.replaceState(null, "", window.location.pathname + "?result");
        }
        storeDel(PREFIX + id);
        storeDel(PREFIX + "seen:" + id);
        setCookie("yquiz_" + id, "", 0, "/");
        if (getCookie("yquizn_" + id) !== null) setCookie("yquizn_" + id, "", 0, "/"); // the name of the finished attempt
        var last = storeGet(PREFIX + "last:" + id);
        if (token || !last || last.s !== data.score) {
            storeSet(PREFIX + "last:" + id, { s: data.score, d: localDay(), t: Date.now() });
        }
        var forget = box.querySelector(".quiz-forget");
        if (forget) forget.addEventListener("click", function () {
            askConfirm(t, t.forgetConfirm || "", function () {
                forgetQuiz(id);
                window.location.href = window.location.pathname;
            });
        });
        if (box.getBoundingClientRect().top > 120) box.scrollIntoView({ block: "start" });
        if (box.querySelector(".quiz-passage")) releaseHeaders();
        var download = box.querySelector(".quiz-cert-download");
        if (download && data.certificate) download.addEventListener("click", function () {
            try { downloadCertificate(data.certificate, t); } catch (err) { toast(t.certError); }
        });
        var opener = box.querySelector(".quiz-cert-open");
        if (data.cert !== "open" || !opener) return;
        var modal = createModal(data, t, opener);
        opener.addEventListener("click", function () { if (!opener.disabled) modal.open(); });
        setTimeout(function () { if (!opener.disabled) modal.open(); }, 450);
    }

    function markIssued(button, name, t) {
        button.disabled = true;
        button.classList.add("is-done");
        button.textContent = (t.certIssued || "").replace("@name", name);
    }

    function createModal(data, t, opener) {
        var uid = "quiz-modal-" + data.id;
        var wrap = document.createElement("div");
        wrap.className = "quiz-modal";
        wrap.innerHTML =
            '<div class="quiz-modal-card" role="dialog" aria-modal="true" aria-labelledby="' + uid + '-title">' +
            '<button type="button" class="quiz-modal-x" data-close aria-label="' + esc(t.close) + '">&times;</button>' +
            '<div class="quiz-modal-grade" aria-hidden="true">' + esc(num(data.score)) + '</div>' +
            '<div class="quiz-modal-title" role="heading" aria-level="2" id="' + uid + '-title">' + esc(t.modalTitle) + '</div>' +
            '<p class="quiz-modal-sub">' + esc(data.title) + '</p>' +
            '<form class="quiz-modal-form" data-step="name" novalidate>' +
            '<label for="' + uid + '-name">' + esc(t.namePrompt) + '</label>' +
            '<input type="text" id="' + uid + '-name" maxlength="60" autocomplete="name" placeholder="' + esc(t.namePlaceholder) + '" />' +
            '<p class="quiz-modal-note">' + esc(t.certWarning) + '</p>' +
            '<div class="quiz-modal-actions">' +
            '<button type="button" class="quiz-btn quiz-btn-quiet" data-close>' + esc(t.close) + '</button>' +
            '<button type="submit" class="quiz-btn">' + esc(t.certContinue) + '</button>' +
            '</div></form>' +
            '<div data-step="confirm" hidden>' +
            '<p class="quiz-modal-ask">' + esc(t.certConfirm) + '</p>' +
            '<p class="quiz-modal-name"></p>' +
            '<p class="quiz-modal-note">' + esc(t.certWarning) + '</p>' +
            '<div class="quiz-modal-actions">' +
            '<button type="button" class="quiz-btn quiz-btn-quiet" data-back>' + esc(t.certEdit) + '</button>' +
            '<button type="button" class="quiz-btn" data-create>' + esc(t.certConfirmYes) + '</button>' +
            '</div></div>' +
            '<div data-step="done" hidden>' +
            '<p class="quiz-modal-message" role="status"></p>' +
            '<div class="quiz-modal-actions"><button type="button" class="quiz-btn" data-close>' + esc(t.close) + '</button></div>' +
            '</div>' +
            '<p class="quiz-modal-error" role="alert" hidden></p>' +
            '</div>';
        document.body.appendChild(english(wrap));

        var input = wrap.querySelector("input");
        var form = wrap.querySelector("form");
        var nameView = wrap.querySelector(".quiz-modal-name");
        var message = wrap.querySelector(".quiz-modal-message");
        var errorView = wrap.querySelector(".quiz-modal-error");
        var backButton = wrap.querySelector("[data-back]");
        var createButton = wrap.querySelector("[data-create]");
        var steps = wrap.querySelectorAll("[data-step]");
        var pendingName = "", busy = false, lastFocus = null;
        input.value = storeGet(PREFIX + "name") || ""; // the last name used in this browser, can be changed

        function show(step) {
            for (var i = 0; i < steps.length; i++) steps[i].hidden = steps[i].getAttribute("data-step") !== step;
        }
        function showError(text) { errorView.textContent = text || ""; errorView.hidden = !text; }
        function open() {
            lastFocus = document.activeElement;
            wrap.classList.add("is-open");
            document.documentElement.classList.add("quiz-modal-lock");
            setTimeout(function () { input.focus(); }, 80);
        }
        function close() {
            if (busy) return;
            wrap.classList.remove("is-open");
            document.documentElement.classList.remove("quiz-modal-lock");
            if (lastFocus && lastFocus.focus && !lastFocus.disabled) lastFocus.focus();
        }
        function finish(name, text) {
            markIssued(opener, name, t);
            message.textContent = (text || "").replace("@name", name);
            show("done");
        }
        wrap.addEventListener("click", function (e) {
            if (e.target === wrap || (e.target.closest && e.target.closest("[data-close]"))) close();
        });
        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape" && wrap.classList.contains("is-open")) close();
        });
        input.addEventListener("input", function () { input.classList.remove("is-invalid"); });
        form.addEventListener("submit", function (e) {
            e.preventDefault();
            var name = input.value.replace(/\s+/g, " ").trim();
            if (!name) { input.classList.add("is-invalid"); input.focus(); return; }
            pendingName = name;
            nameView.textContent = name;
            showError("");
            show("confirm");
            createButton.focus();
        });
        backButton.addEventListener("click", function () { show("name"); input.focus(); });
        createButton.addEventListener("click", function () {
            if (busy) return;
            busy = true;
            createButton.disabled = backButton.disabled = true;
            createButton.classList.add("is-busy");
            showError("");
            storeSet(PREFIX + "name", pendingName); // remembered for the next certificate in this browser
            requestCertificate(data, pendingName).then(function (result) {
                if (result.ok && result.kept) {
                    finish(result.name, (t.certKept || "").replace("@score", num(result.score)));
                    try { downloadCertificate(result, t); } catch (err) { showError(t.certError); }
                } else if (result.ok) {
                    finish(result.name, t.certIssued);
                    try { downloadCertificate(result, t); } catch (err) { showError(t.certError); }
                } else if (result.error === "used") {
                    finish(result.issuedTo || pendingName, t.certUsed);
                } else if (result.error === "name") {
                    show("name");
                    showError(t.certError);
                } else if (result.error === "expired") {
                    showError(t.certExpired || t.certError);
                } else if (result.error === "storage") {
                    showError(t.storageError || t.certError);
                } else {
                    showError(t.certError);
                }
            }, function () {
                showError(t.certError);
            }).then(function () {
                busy = false;
                createButton.disabled = backButton.disabled = false;
                createButton.classList.remove("is-busy");
            });
        });
        return { open: open, close: close };
    }

    // Ask the server for the one-time certificate of this attempt
    function requestCertificate(data, name) {
        var body = new FormData();
        body.append("quiz_cert", "1");
        body.append("cert_payload", data.payload || "");
        body.append("cert_sig", data.sig || "");
        body.append("cert_name", name);
        body.append("cert_device", getDevice());
        return postForm(body);
    }
    function postForm(body) {
        return fetch(window.location.pathname, {
            method: "POST", body: body, credentials: "same-origin", cache: "no-store",
            headers: { "Accept": "application/json" }
        }).then(function (response) { return response.text(); }).then(parseCertificateResponse);
    }
    function parseCertificateResponse(text) {
        try { return JSON.parse(text); } catch (e) { /* in the editor of Yellow the page comes back, with the answer inside */ }
        var element = new DOMParser().parseFromString(text, "text/html").querySelector(".quiz-cert-response");
        if (element) { try { return JSON.parse(element.getAttribute("data-response")); } catch (e) { /* not an answer */ } }
        return { ok: false, error: "network" };
    }

    // ---------------------------------------------------------------- reprint
    function initReprint(box) {
        var t = readJSON(box, "data-i18n");
        var form = box.querySelector(".quiz-reprint-form");
        var input = box.querySelector("input");
        var message = box.querySelector(".quiz-reprint-message");
        var button = form ? form.querySelector("button[type=submit]") : null;
        if (!form || !input || !message || !button) return;
        function say(text, ok) {
            message.textContent = text || "";
            message.hidden = !text;
            message.classList.toggle("is-ok", !!ok);
        }
        input.addEventListener("input", function () { say(""); });
        form.addEventListener("submit", function (e) {
            e.preventDefault();
            var number = input.value.replace(/[\s-]+/g, "").toUpperCase();
            if (!/^[0-9A-F]{10}$/.test(number)) { say(t.reprintInvalid, false); input.focus(); return; }
            button.disabled = true;
            button.classList.add("is-busy");
            var body = new FormData();
            body.append("quiz_reprint", "1");
            body.append("number", number);
            postForm(body).then(function (result) {
                if (result.ok) {
                    try { downloadCertificate(result, t); say((t.reprintDone || "").replace("@name", result.name), true); }
                    catch (err) { say(t.certError, false); }
                } else if (result.error === "not_found") {
                    say(t.reprintNotFound, false);
                } else if (result.error === "invalid") {
                    say(t.reprintInvalid, false);
                } else {
                    say(t.certError, false);
                }
            }, function () { say(t.certError, false); }).then(function () {
                button.disabled = false;
                button.classList.remove("is-busy");
            });
        });
    }

    // ---------------------------------------------------------------- data page [quizdata]
    // Read only. The list of record files, or the rows of one file: search, sorting, summary, essays, download.
    var DATA_PAGE_SIZE = 50;
    var DATA_COLUMNS = { issued: "dataColIssued", certificate_no: "dataColCertificate", name: "dataColName", device: "dataColDevice",
        minutes: "dataColMinutes", score: "dataColScore", quiz_title: "dataColTitle", attempt: "dataColAttempt", parts: "dataColParts",
        identity: "dataColIdentity", essays: "dataColEssays" };
    var DATA_TECHNICAL = ["device", "attempt", "identity", "quiz_title"];
    function make(tag, className, text) {
        var element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined && text !== null) element.textContent = String(text);
        return element;
    }
    function toCsv(rows) {
        return rows.map(function (row) {
            return row.map(function (value) {
                var text = value === null || value === undefined ? "" : String(value);
                return /[",\r\n]|^\s|\s$/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
            }).join(",");
        }).join("\n") + "\n";
    }
    function median(list) {
        var sorted = list.slice().sort(function (a, b) { return a - b; });
        var middle = Math.floor(sorted.length / 2);
        return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
    }
    function formatSize(bytes) {
        if (bytes < 1024) return bytes + " B";
        if (bytes < 1048576) return (Math.round(bytes / 102.4) / 10) + " KB";
        return (Math.round(bytes / 104857.6) / 10) + " MB";
    }
    function decodeEssays(text) {
        try { var data = JSON.parse(text || ""); return data && data.items ? data : null; } catch (e) { return null; }
    }
    function initData(app) {
        var data = readJSON(app, "data-data");
        var t = readJSON(app, "data-i18n");
        app.innerHTML = "";
        var bar = make("div", "quiz-data-bar");
        app.appendChild(bar);
        var refresh = make("button", "quiz-btn quiz-btn-quiet", t.dataRefresh);
        refresh.type = "button";
        refresh.addEventListener("click", function () { window.location.reload(); });
        var updated = make("span", "quiz-data-updated", (t.dataUpdated || "").replace("@time", data.updated || ""));
        if (!data.file) {
            bar.appendChild(refresh);
            bar.appendChild(updated);
            var fileBox = make("div", "quiz-data-scroll");
            var fileTable = make("table", "quiz-data-table");
            var fileHead = fileTable.createTHead().insertRow();
            [t.dataQuiz, t.dataFile, t.dataKind, t.dataRows, t.dataLast, t.dataSize].forEach(function (label) { fileHead.appendChild(make("th", "", label)); });
            var fileBody = fileTable.createTBody();
            (data.files || []).forEach(function (file) {
                var row = fileBody.insertRow();
                var link = make("a", "", file.title || file.file);
                link.href = data.page + "?quiz_data=" + encodeURIComponent(file.file);
                row.insertCell().appendChild(link);
                row.insertCell().textContent = file.file;
                row.insertCell().textContent = file.kind === "essays" ? t.dataKindEssays : (file.kind === "base" ? t.dataKindBase : t.dataKindOther);
                row.insertCell().textContent = file.rows;
                row.insertCell().textContent = file.last || "";
                row.insertCell().textContent = formatSize(file.size || 0);
            });
            fileBox.appendChild(fileTable);
            app.appendChild(fileBox);
            return;
        }
        // ---- one file
        var back = make("a", "quiz-btn quiz-btn-quiet", "← " + (t.dataBack || ""));
        back.href = data.page;
        var download = make("button", "quiz-btn quiz-btn-quiet", t.dataDownload);
        download.type = "button";
        var header = data.file.header || [];
        var rows = data.file.rows || [];
        download.addEventListener("click", function () {
            var link = document.createElement("a");
            link.href = URL.createObjectURL(new Blob([toCsv([header].concat(rows))], { type: "text/csv;charset=utf-8" }));
            link.download = data.file.name;
            document.body.appendChild(link);
            link.click();
            link.parentNode.removeChild(link);
            setTimeout(function () { URL.revokeObjectURL(link.href); }, 5000);
        });
        [back, refresh, download, updated].forEach(function (item) { bar.appendChild(item); });
        var info = (data.files || []).filter(function (file) { return file.file === data.file.name; })[0] || {};
        app.appendChild(make("div", "quiz-data-heading", (info.title ? info.title + " · " : "") + data.file.name));
        var column = function (name) { return header.indexOf(name); };
        // parts "Grammar=80;Vocabulary=70" become one column per part
        var partNames = [];
        var partsAt = column("parts");
        rows.forEach(function (row) {
            String(partsAt >= 0 ? row[partsAt] || "" : "").split(";").forEach(function (item) {
                var name = item.split("=")[0].trim();
                if (name && partNames.indexOf(name) < 0) partNames.push(name);
            });
        });
        var records = rows.map(function (row) {
            var record = { row: row, parts: {} };
            String(partsAt >= 0 ? row[partsAt] || "" : "").split(";").forEach(function (item) {
                var pair = item.split("=");
                if (pair.length === 2 && pair[0].trim()) record.parts[pair[0].trim()] = parseFloat(pair[1]);
            });
            var essaysAt = column("essays");
            record.essays = essaysAt >= 0 ? decodeEssays(row[essaysAt]) : null;
            return record;
        });
        // the columns shown: the stored ones without "parts" and "essays", then one per part, then the essays
        var columns = [];
        header.forEach(function (name, index) {
            if (name === "parts" || name === "essays") return;
            columns.push({ key: name, label: t[DATA_COLUMNS[name]] || name, technical: DATA_TECHNICAL.indexOf(name) >= 0,
                value: function (record) { return record.row[index] === undefined ? "" : record.row[index]; } });
        });
        partNames.forEach(function (part) {
            columns.push({ key: "part:" + part, label: part, technical: false,
                value: function (record) { return record.parts[part] === undefined ? "" : String(record.parts[part]); } });
        });
        var hasEssays = column("essays") >= 0;
        // controls: search, technical columns, summary
        var controls = make("div", "quiz-data-controls");
        var search = make("input", "quiz-data-search");
        search.type = "search";
        search.placeholder = t.dataSearch || "";
        search.setAttribute("aria-label", t.dataSearch || "");
        var technicalLabel = make("label", "quiz-data-check");
        var technical = make("input");
        technical.type = "checkbox";
        technicalLabel.appendChild(technical);
        technicalLabel.appendChild(document.createTextNode(" " + (t.dataTechnical || "")));
        controls.appendChild(search);
        controls.appendChild(technicalLabel);
        app.appendChild(controls);
        var summary = make("div", "quiz-data-summary");
        app.appendChild(summary);
        app.appendChild(make("p", "quiz-data-hint", t.dataSortHint));
        var scroll = make("div", "quiz-data-scroll");
        app.appendChild(scroll);
        var pager = make("div", "quiz-data-pager");
        app.appendChild(pager);
        var sortKey = "issued", sortUp = false, page = 0;
        var open = {};
        function filtered() {
            var query = search.value.trim().toLowerCase();
            var nameAt = column("name"), numberAt = column("certificate_no");
            var list = records.filter(function (record) {
                if (!query) return true;
                return String(record.row[nameAt] || "").toLowerCase().indexOf(query) >= 0 || String(record.row[numberAt] || "").toLowerCase().indexOf(query) >= 0;
            });
            var current = columns.filter(function (item) { return item.key === sortKey; })[0];
            if (current) {
                list.sort(function (a, b) {
                    var x = current.value(a), y = current.value(b);
                    var numeric = x !== "" && y !== "" && !isNaN(Number(x)) && !isNaN(Number(y));
                    var order = numeric ? Number(x) - Number(y) : String(x).localeCompare(String(y));
                    return sortUp ? order : -order;
                });
            }
            return list;
        }
        function showSummary(list) {
            summary.innerHTML = "";
            var scoreAt = column("score"), numberAt = column("certificate_no");
            var scores = list.map(function (record) { return parseFloat(record.row[scoreAt]); }).filter(function (value) { return !isNaN(value); });
            var certified = list.filter(function (record) { return numberAt >= 0 && record.row[numberAt]; }).length;
            var items = [[t.dataCount, list.length], [t.dataCertified, certified]];
            if (scores.length) {
                var mean = scores.reduce(function (a, b) { return a + b; }, 0) / scores.length;
                items.push([t.dataMean, num(mean)], [t.dataMedian, num(median(scores))], [t.dataMin, num(Math.min.apply(null, scores))], [t.dataMax, num(Math.max.apply(null, scores))]);
            }
            partNames.forEach(function (part) {
                var values = list.map(function (record) { return record.parts[part]; }).filter(function (value) { return typeof value === "number" && !isNaN(value); });
                if (values.length) items.push([part + " (" + t.dataMean + ")", num(values.reduce(function (a, b) { return a + b; }, 0) / values.length)]);
            });
            items.forEach(function (item) {
                var cell = make("span", "quiz-data-stat");
                cell.appendChild(make("span", "", item[0]));
                cell.appendChild(make("span", "quiz-data-stat-value", item[1]));
                summary.appendChild(cell);
            });
        }
        function essayView(record) {
            var wrap = make("div", "quiz-data-essays");
            var essays = record.essays;
            var notes = [];
            if (essays.auto === "time") notes.push(t.dataAutoTime);
            if (essays.auto === "away") notes.push(t.dataAutoAway);
            if (essays.late) notes.push(t.dataLate);
            wrap.appendChild(make("p", "quiz-data-essay-time", essays.time + (notes.length ? " · " + notes.join(" · ") : "")));
            (essays.items || []).forEach(function (item) {
                var block = make("div", "quiz-data-essay");
                var limit = item.min > 0 ? (t.dataLimitRange || "").replace("@min", item.min).replace("@max", item.max) : (t.dataLimitMax || "").replace("@max", item.max);
                var flags = [(t.dataWords || "").replace("@count", item.words), limit];
                if (item.words > item.max) flags.push(t.dataOver);
                if (item.words < item.min) flags.push(t.dataUnder);
                block.appendChild(make("p", "quiz-data-essay-head", (t.dataQuestion || "").replace("@no", item.no) + " · " + flags.join(" · ")));
                block.appendChild(make("p", "quiz-data-essay-question", item.question));
                block.appendChild(make("div", "quiz-data-essay-answer" + (item.answer ? "" : " is-empty"), item.answer || t.dataNoAnswer));
                wrap.appendChild(block);
            });
            return wrap;
        }
        function render() {
            var list = filtered();
            showSummary(list);
            var shown = columns.filter(function (item) { return technical.checked || !item.technical; });
            var pages = Math.max(1, Math.ceil(list.length / DATA_PAGE_SIZE));
            page = Math.min(page, pages - 1);
            var table = make("table", "quiz-data-table");
            var head = table.createTHead().insertRow();
            shown.forEach(function (item) {
                var cell = make("th", item.key === sortKey ? (sortUp ? "is-up" : "is-down") : "");
                var button = make("button", "quiz-data-sort", item.label);
                button.type = "button";
                button.addEventListener("click", function () {
                    if (sortKey === item.key) sortUp = !sortUp; else { sortKey = item.key; sortUp = true; }
                    render();
                });
                cell.appendChild(button);
                head.appendChild(cell);
            });
            if (hasEssays) head.appendChild(make("th", "", t.dataColEssays));
            var body = table.createTBody();
            if (!records.length || !list.length) {
                var empty = body.insertRow().insertCell();
                empty.colSpan = shown.length + (hasEssays ? 1 : 0);
                empty.textContent = records.length ? t.dataNone : t.dataEmpty;
            }
            list.slice(page * DATA_PAGE_SIZE, (page + 1) * DATA_PAGE_SIZE).forEach(function (record) {
                var row = body.insertRow();
                shown.forEach(function (item) { row.insertCell().textContent = item.value(record); });
                if (!hasEssays) return;
                var cell = row.insertCell();
                if (!record.essays) return;
                var key = records.indexOf(record);
                var toggle = make("button", "quiz-data-essay-toggle", open[key] ? t.dataHideEssays : t.dataShowEssays);
                toggle.type = "button";
                toggle.addEventListener("click", function () { open[key] = !open[key]; render(); });
                cell.appendChild(toggle);
                if (open[key]) {
                    var detail = body.insertRow().insertCell();
                    detail.colSpan = shown.length + 1;
                    detail.className = "quiz-data-detail";
                    detail.appendChild(essayView(record));
                }
            });
            scroll.innerHTML = "";
            scroll.appendChild(table);
            pager.innerHTML = "";
            if (pages > 1) {
                var previous = make("button", "quiz-btn quiz-btn-quiet", t.dataPrev);
                var next = make("button", "quiz-btn quiz-btn-quiet", t.dataNext);
                previous.type = next.type = "button";
                previous.disabled = page === 0;
                next.disabled = page >= pages - 1;
                previous.addEventListener("click", function () { page--; render(); });
                next.addEventListener("click", function () { page++; render(); });
                pager.appendChild(previous);
                pager.appendChild(make("span", "quiz-data-page", (t.dataPage || "").replace("@page", page + 1).replace("@pages", pages)));
                pager.appendChild(next);
            }
        }
        search.addEventListener("input", function () { page = 0; render(); });
        technical.addEventListener("change", render);
        render();
    }

    // ---------------------------------------------------------------- certificate
    // A4 landscape at about 200 dpi; drawn once on the student's device, then saved as a small PDF
    function downloadCertificate(d, t) {
        var W = 2339, H = 1654, cx = W / 2;
        var canvas = document.createElement("canvas");
        canvas.width = W; canvas.height = H;
        var c = canvas.getContext("2d");
        var ink = "#1b2240", muted = "#5d6478", teal = "#0f9d94", violet = "#6d3fd0";
        var serif = "Georgia, 'Times New Roman', serif";
        var sans = "'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";
        var name = String(d.name || "");
        var diagonal = function () { var g = c.createLinearGradient(0, 0, W, H); g.addColorStop(0, teal); g.addColorStop(1, violet); return g; };

        // background and soft colour wash in two corners
        c.fillStyle = "#ffffff"; c.fillRect(0, 0, W, H);
        var wash = c.createRadialGradient(0, 0, 0, 0, 0, 900); wash.addColorStop(0, "rgba(15,157,148,0.10)"); wash.addColorStop(1, "rgba(15,157,148,0)");
        c.fillStyle = wash; c.fillRect(0, 0, W, H);
        wash = c.createRadialGradient(W, H, 0, W, H, 900); wash.addColorStop(0, "rgba(109,63,208,0.10)"); wash.addColorStop(1, "rgba(109,63,208,0)");
        c.fillStyle = wash; c.fillRect(0, 0, W, H);

        // frame: thick teal-violet gradient border, thin inner line, small corner diamonds
        c.strokeStyle = diagonal(); c.lineWidth = 28; c.strokeRect(64, 64, W - 128, H - 128);
        c.strokeStyle = diagonal(); c.lineWidth = 3; c.strokeRect(104, 104, W - 208, H - 208);
        [[104, 104], [W - 104, 104], [104, H - 104], [W - 104, H - 104]].forEach(function (p) { diamond(c, p[0], p[1], 16, diagonal()); });

        c.textAlign = "center"; c.textBaseline = "alphabetic";
        if (d.site) { c.fillStyle = muted; c.font = "600 38px " + sans; spaced(c, String(d.site).toUpperCase(), cx, 235, 6); }
        c.fillStyle = ink;
        fit(c, t.certHeading || "Certificate of Completion", "700", serif, 100, 56, W - 520);
        c.fillText(t.certHeading || "Certificate of Completion", cx, 365);
        c.fillStyle = diagonal(); c.fillRect(cx - 130, 400, 260, 6);

        c.fillStyle = muted; c.font = "italic 44px " + serif;
        c.fillText(t.certIntro || "", cx, 520);

        // name in teal-violet gradient
        fit(c, name, "italic 700", serif, 140, 60, W - 640);
        var nameWidth = Math.min(c.measureText(name).width, W - 640);
        var nameFill = c.createLinearGradient(cx - nameWidth / 2, 0, cx + nameWidth / 2, 0);
        nameFill.addColorStop(0, teal); nameFill.addColorStop(1, violet);
        c.fillStyle = nameFill; c.fillText(name, cx, 680);
        c.fillStyle = nameFill; c.fillRect(cx - nameWidth / 2 - 50, 712, nameWidth + 100, 4);

        c.fillStyle = muted; c.font = "italic 42px " + serif;
        c.fillText(t.certCompleted || "", cx, 800);

        c.fillStyle = ink;
        var size = 66, lines;
        do { c.font = "700 " + size + "px " + sans; lines = wrapLines(c, String(d.title || ""), W - 700); size -= 4; }
        while (lines.length > 2 && size >= 40);
        if (lines.length > 2) { lines = lines.slice(0, 2); lines[1] += " …"; }
        for (var i = 0; i < lines.length; i++) c.fillText(lines[i], cx, 885 + i * 78);
        var y = 885 + (lines.length - 1) * 78;

        if (typeof d.score === "number") {
            y += 74;
            c.fillStyle = muted;
            var mastery = (t.certMastery || "").replace("@score", num(d.score));
            fit(c, mastery, "italic", serif, 42, 28, W - 700);
            c.fillText(mastery, cx, y);
        }
        // mastery by part: one sentence, smaller, wrapped to at most three lines
        if (d.parts && d.parts.length) {
            var list = d.parts.map(function (p) { return p[0] + " " + num(p[1]) + "%"; }).join(", ");
            var sentence = (t.certParts || "@list").replace("@list", list);
            var partSize = 32, partLines;
            do { c.font = partSize + "px " + sans; partLines = wrapLines(c, sentence, W - 760); partSize -= 2; }
            while (partLines.length > 3 && partSize >= 20);
            if (partLines.length > 3) { partLines = partLines.slice(0, 3); partLines[2] += " …"; }
            c.fillStyle = muted;
            for (var k = 0; k < partLines.length; k++) c.fillText(partLines[k], cx, y + 58 + k * (partSize + 12));
        }

        // bottom row: date (left), score circled in red ink (centre), certificate number with stamp (right)
        footerItem(c, W * 0.22, formatDate(d.date), t.certDate || "Date", ink, muted, sans);
        var scoreText = num(d.score);
        c.fillStyle = ink; fit(c, scoreText, "700", serif, 84, 40, 260);
        var scoreSize = c.measureText(scoreText), scoreHeight = scoreSize.actualBoundingBoxAscent;
        c.fillText(scoreText, cx, 1400);
        // the circle is centred on the digits and keeps clear of them and of the "Score" label below
        handCircle(c, cx, 1400 - scoreHeight / 2, Math.max(105, scoreSize.width / 2 + 58), scoreHeight / 2 + 36, String(d.code || ""));
        c.fillStyle = muted; c.font = "34px " + sans; c.fillText(t.certScore || "Score", cx, 1480);
        // the stamp stands against the right edge of the frame; the certificate number ends 16 px left of the stamp,
        // so the stamp is close to the number but never over its characters, whatever the width of the number
        var stampR = 140, sx = W - 104 - 14 - stampR - 4;
        c.font = "600 42px " + sans;
        var nx = sx - stampR - 4 - 16 - c.measureText(String(d.code || "")).width / 2;
        footerItem(c, nx, String(d.code || ""), t.certNumber || "No.", ink, muted, sans);
        drawStamp(c, sx, 1392, stampR, String(d.site || ""), String(d.date || "").slice(0, 4), teal, violet, sans, serif); // pressed over the line, like a real stamp

        var dataUrl = canvas.toDataURL("image/jpeg", 0.9);
        var binary = atob(dataUrl.split(",")[1]);
        var bytes = new Uint8Array(binary.length);
        for (var b = 0; b < binary.length; b++) bytes[b] = binary.charCodeAt(b);
        var blob = jpegToPdf(bytes, W, H);
        var link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = "certificate-" + slug(name) + ".pdf";
        document.body.appendChild(link);
        link.click();
        link.parentNode.removeChild(link);
        setTimeout(function () { URL.revokeObjectURL(link.href); }, 5000);
    }

    // Round ink stamp: the site name around the ring (repeated with ★ when it is short), "MA" and the year in the centre.
    // Names of up to 50 characters fit without cutting; the letters are spread evenly so the ring has no gap.
    function drawStamp(c, x, y, r, site, year, teal, violet, sans, serif) {
        c.save();
        c.translate(x, y);
        c.rotate(-0.21);
        c.globalAlpha = 0.82;
        var ink = c.createLinearGradient(-r, -r, r, r); ink.addColorStop(0, teal); ink.addColorStop(1, violet);
        c.strokeStyle = ink; c.fillStyle = ink;
        var textRadius = r - 32, inner = r - 52;
        c.lineWidth = 7; c.beginPath(); c.arc(0, 0, r, 0, Math.PI * 2); c.stroke();
        c.lineWidth = 2.5; c.beginPath(); c.arc(0, 0, r - 12, 0, Math.PI * 2); c.stroke();
        c.lineWidth = 2.5; c.beginPath(); c.arc(0, 0, inner, 0, Math.PI * 2); c.stroke();
        // site name: the largest size from 26 down to 11 px at which the name and one ★ fit the ring
        var label = (site || "").toUpperCase().trim() || "CERTIFIED";
        var circle = 2 * Math.PI * textRadius, room = circle * 0.96, fontSize = 26;
        c.font = "700 " + fontSize + "px " + sans;
        while (c.measureText(label + " ★ ").width > room && fontSize > 11) { fontSize -= 0.5; c.font = "700 " + fontSize + "px " + sans; }
        while (c.measureText(label + " ★ ").width > room && label.length > 4) label = label.slice(0, -2).trim() + "…"; // only for very long names
        var unit = label + " ★ ", text = unit;
        while (c.measureText(text + unit).width <= room) text += unit;
        var widths = [], total = 0;
        for (var i = 0; i < text.length; i++) { widths[i] = c.measureText(text[i]).width; total += widths[i]; }
        var gap = (circle - total) / text.length;
        var labelArc = 0;
        for (var k = 0; k < label.length; k++) labelArc += (widths[k] + gap) / textRadius;
        var angle = -(labelArc - gap / textRadius) / 2; // the first name is centred at the top of the stamp
        c.textAlign = "center"; c.textBaseline = "middle";
        for (var j = 0; j < text.length; j++) {
            angle += (widths[j] / 2) / textRadius;
            c.save(); c.rotate(angle); c.fillText(text[j], 0, -textRadius); c.restore();
            angle += (widths[j] / 2 + gap) / textRadius;
        }
        // centre: "MA" above the year, both inside the inner circle with at least 8 px to spare
        var limit = inner - 8 - 1.25, maSize = Math.round(inner * 0.9), maWidth, yearSize, yearWidth, maBase, yearBase;
        var corner = function (halfWidth, height) { return Math.sqrt(halfWidth * halfWidth + height * height); };
        for (; maSize > 12; maSize--) {
            c.font = "700 " + maSize + "px " + serif; maWidth = c.measureText("MA").width;
            yearSize = Math.round(maSize * 0.38);
            c.font = "700 " + yearSize + "px " + sans; yearWidth = c.measureText(year || "").width;
            var maCap = maSize * 0.7, yearCap = yearSize * 0.72, space = maSize * 0.16;
            var top = -(maCap + space + yearCap) / 2;
            maBase = top + maCap; yearBase = maBase + space + yearCap;
            if (corner(maWidth / 2, top) <= limit && corner(maWidth / 2, maBase) <= limit &&
                corner(yearWidth / 2, maBase + space) <= limit && corner(yearWidth / 2, yearBase) <= limit) break;
        }
        c.textBaseline = "alphabetic";
        c.font = "700 " + maSize + "px " + serif; c.fillText("MA", 0, maBase);
        c.font = "700 " + yearSize + "px " + sans; c.fillText(year || "", 0, yearBase);
        c.restore();
        c.textAlign = "center"; c.textBaseline = "alphabetic";
    }
    // Red ink circle around the score, drawn like one quick pen stroke: slightly uneven, tilted, thicker in the middle,
    // and ending a little past its start. The unevenness comes from the certificate number, so a reprint looks the same.
    function handCircle(c, x, y, rx, ry, seedText) {
        var state = 2166136261;
        for (var i = 0; i < seedText.length; i++) state = Math.imul(state ^ seedText.charCodeAt(i), 16777619) >>> 0;
        var random = function () {
            state = (state + 0x6D2B79F5) >>> 0;
            var t = Math.imul(state ^ (state >>> 15), state | 1);
            t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
            return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
        };
        var start = -2.3 + (random() - 0.5) * 0.6;       // the pen starts at the upper left
        var sweep = Math.PI * 2 + 0.35 + random() * 0.25; // once round and a little further
        var tilt = -0.05 + (random() - 0.5) * 0.05;
        var wave1 = 0.03 + random() * 0.015, wave2 = 0.015 + random() * 0.01, phase1 = random() * 6.28, phase2 = random() * 6.28;
        var steps = 140, previous = null;
        c.save();
        c.translate(x, y); c.rotate(tilt);
        c.strokeStyle = "rgba(200, 30, 45, 0.88)"; c.lineCap = "round"; c.lineJoin = "round";
        for (var s = 0; s <= steps; s++) {
            var f = s / steps, a = start + sweep * f;
            var wobble = (1 + wave1 * Math.sin(2 * a + phase1) + wave2 * Math.sin(3 * a + phase2)) * (1 + 0.06 * f);
            var point = [Math.cos(a) * rx * wobble, Math.sin(a) * ry * wobble];
            if (previous) {
                c.lineWidth = 2.5 + 5.5 * Math.sin(Math.PI * Math.min(1, f * 1.15));
                c.beginPath(); c.moveTo(previous[0], previous[1]); c.lineTo(point[0], point[1]); c.stroke();
            }
            previous = point;
        }
        c.restore();
    }
    function diamond(c, x, y, r, fill) {
        c.fillStyle = fill;
        c.beginPath(); c.moveTo(x, y - r); c.lineTo(x + r, y); c.lineTo(x, y + r); c.lineTo(x - r, y); c.closePath(); c.fill();
    }
    function footerItem(c, x, value, label, ink, muted, sans) {
        c.fillStyle = ink; c.font = "600 42px " + sans;
        c.fillText(value, x, 1408);
        c.strokeStyle = ink; c.lineWidth = 2;
        c.beginPath(); c.moveTo(x - 240, 1432); c.lineTo(x + 240, 1432); c.stroke();
        c.fillStyle = muted; c.font = "34px " + sans;
        c.fillText(label, x, 1480);
    }
    function fit(c, text, style, family, size, min, maxWidth) {
        do { c.font = style + " " + size + "px " + family; size -= 2; }
        while (c.measureText(text).width > maxWidth && size >= min);
    }
    function wrapLines(c, text, maxWidth) {
        var words = text.split(/\s+/), lines = [], line = "";
        for (var i = 0; i < words.length; i++) {
            var test = line ? line + " " + words[i] : words[i];
            if (c.measureText(test).width > maxWidth && line) { lines.push(line); line = words[i]; }
            else line = test;
        }
        if (line) lines.push(line);
        return lines;
    }
    function spaced(c, text, x, y, spacing) {
        if ("letterSpacing" in c) { c.letterSpacing = spacing + "px"; c.fillText(text, x, y); c.letterSpacing = "0px"; }
        else c.fillText(text, x, y);
    }
    function num(value) {
        var n = Number(value);
        return isNaN(n) ? String(value) : String(Math.round(n * 10) / 10);
    }
    // Dates are always English, for example "4 October 2026", whatever the language of the page or browser
    var MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    function formatDate(iso) {
        var parts = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || "");
        var date = parts ? new Date(+parts[1], parts[2] - 1, +parts[3]) : new Date();
        if (isNaN(date.getTime())) date = new Date();
        return date.getDate() + " " + MONTHS[date.getMonth()] + " " + date.getFullYear();
    }
    // Today on this device as YYYY-MM-DD
    function localDay() {
        var now = new Date();
        return now.getFullYear() + "-" + ("0" + (now.getMonth() + 1)).slice(-2) + "-" + ("0" + now.getDate()).slice(-2);
    }
    function slug(text) {
        var s = text;
        if (s.normalize) s = s.normalize("NFKD").replace(/[\u0300-\u036f]/g, "");
        s = s.replace(/[^A-Za-z0-9]+/g, "-").replace(/^-+|-+$/g, "").toLowerCase();
        return s || "quiz";
    }

    // Minimal PDF writer: one A4 landscape page holding one JPEG image
    function jpegToPdf(jpeg, width, height) {
        var encoder = new TextEncoder();
        var chunks = [], offsets = [], length = 0;
        function add(part) {
            var bytes = typeof part === "string" ? encoder.encode(part) : part;
            chunks.push(bytes); length += bytes.length;
        }
        var pageW = 842, pageH = 595;
        var content = "q " + pageW + " 0 0 " + pageH + " 0 0 cm /Im0 Do Q";
        add("%PDF-1.4\n");
        add(new Uint8Array([37, 226, 227, 207, 211, 10]));
        var objects = [
            "<< /Type /Catalog /Pages 2 0 R >>",
            "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " + pageW + " " + pageH + "] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>",
            null,
            "<< /Length " + content.length + " >>\nstream\n" + content + "\nendstream"
        ];
        for (var i = 0; i < objects.length; i++) {
            offsets.push(length);
            add((i + 1) + " 0 obj\n");
            if (objects[i] === null) {
                add("<< /Type /XObject /Subtype /Image /Width " + width + " /Height " + height +
                    " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " + jpeg.length + " >>\nstream\n");
                add(jpeg);
                add("\nendstream");
            } else {
                add(objects[i]);
            }
            add("\nendobj\n");
        }
        var xref = length;
        var table = "xref\n0 " + (objects.length + 1) + "\n0000000000 65535 f \n";
        for (var j = 0; j < offsets.length; j++) table += ("0000000000" + offsets[j]).slice(-10) + " 00000 n \n";
        add(table + "trailer\n<< /Size " + (objects.length + 1) + " /Root 1 0 R >>\nstartxref\n" + xref + "\n%%EOF\n");
        return new Blob(chunks, { type: "application/pdf" });
    }
})();
