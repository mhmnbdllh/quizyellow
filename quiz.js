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
    function setCookie(name, value, seconds) {
        var secure = window.location.protocol === "https:" ? "; Secure" : "";
        document.cookie = name + "=" + encodeURIComponent(value) + "; path=/; max-age=" + seconds + "; SameSite=Lax" + secure;
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
        document.cookie = "yquizr_" + id + "=; path=" + window.location.pathname + "; max-age=0; SameSite=Lax";
        document.cookie = "yquizr_" + id + "=; path=/; max-age=0; SameSite=Lax"; // also a result stored for the whole site
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
        var all = document.querySelectorAll(".quiz-container");
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
        var lifetime = parseInt(box.getAttribute("data-lifetime"), 10) || 86400;
        var t = readJSON(box, "data-i18n");
        var form = box.querySelector("form.quiz-form");
        var stateKey = PREFIX + id;
        var seenKey = PREFIX + "seen:" + id;
        var cookieName = "yquiz_" + id;
        if (!form) return;
        var field = function (name) { return form.querySelector("input[name=" + name + "]"); };

        // JavaScript is running: show the quiz and mark the submission as coming from a real browser
        box.classList.add("quiz-js-ready");
        if (field("quiz_client")) field("quiz_client").value = "1";

        // Remove the retake marker from the address, the page is already a fresh attempt
        if (window.history && history.replaceState && /[?&]quiz_(retake|start)=/.test(window.location.search)) {
            var query = window.location.search.replace(/([?&])quiz_(retake|start)=[^&]*&?/g, "$1").replace(/[?&]$/, "");
            history.replaceState(null, "", window.location.pathname + query + window.location.hash);
        }

        // The signed attempt token is kept in a cookie: after a refresh the server
        // recognises the attempt, keeps its start time and shows the same order
        if (getCookie(cookieName) !== attempt) setCookie(cookieName, attempt, lifetime);

        // Answers and page leaves of this attempt; the "last seen" time is kept apart because it changes every second
        var state = storeGet(stateKey);
        if (!state || state.attempt !== attempt || typeof state.answers !== "object" || !state.answers) {
            state = { attempt: attempt, answers: {}, away: 0, awayStart: 0 };
        }
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
        function getMissing() {
            var list = [];
            for (var g = 0; g < groups.length; g++) {
                if (!groups[g].querySelector("input[type=radio]:checked")) list.push(groups[g]);
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
        // Coming back with the browser's back button after submitting: start clean
        window.addEventListener("pageshow", function (e) {
            if (e.persisted && sent) window.location.reload();
        });

        // ---- questions not answered yet: marked until they are answered
        function mark(card) {
            if (card.classList.contains("is-missing")) return;
            card.classList.add("is-missing");
            var badge = document.createElement("span");
            badge.className = "quiz-missing-badge";
            badge.textContent = t.unansweredBadge || "";
            card.appendChild(badge);
        }
        function unmark(card) {
            card.classList.remove("is-missing");
            var badge = card.querySelector(".quiz-missing-badge");
            if (badge) badge.parentNode.removeChild(badge);
        }
        function goToCard(card) {
            var top = card.getBoundingClientRect().top + (window.pageYOffset || document.documentElement.scrollTop) - 110;
            try { window.scrollTo({ top: Math.max(0, top), behavior: "smooth" }); } catch (err) { window.scrollTo(0, Math.max(0, top)); }
            var first = card.querySelector("input[type=radio]");
            if (first) { try { first.focus({ preventScroll: true }); } catch (err) { /* old browser */ } }
        }

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
                if (pill) {
                    pill.classList.toggle("is-warning", ratio <= 0.25 && ratio > 0.1);
                    pill.classList.toggle("is-danger", ratio <= 0.1);
                    heat(pill, ratio);
                }
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
        var level = Math.round(h * 100) / 100;
        pill.style.setProperty("--quiz-heat", String(level));
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
            lastView.textContent = (t.last || "").replace("@score", num(last.s)).replace("@date", formatDate(last.d));
            if (hasResult(id)) { // the full result is kept for 24 hours
                var link = document.createElement("a");
                link.href = window.location.pathname + "?result";
                link.className = "quiz-intro-lastlink";
                link.textContent = t.viewLast || "";
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
        var start = box.querySelector(".quiz-start");
        var started = false;
        if (start) start.addEventListener("click", function (e) {
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
    var mediaGuard = false;
    function initMedia(box) {
        // Mermaid diagrams: drawn when the page opens, with the library of the site if there is one
        var diagrams = box.querySelectorAll(".quiz-mermaid:not([data-processed])");
        if (diagrams.length) {
            var draw = function (ownLibrary) {
                var mermaid = window.mermaid;
                if (!mermaid) return;
                try {
                    if (ownLibrary && mermaid.initialize) mermaid.initialize({ startOnLoad: false, securityLevel: "strict" });
                    var nodes = Array.prototype.filter.call(diagrams, function (node) { return !node.getAttribute("data-processed"); });
                    if (mermaid.run) mermaid.run({ nodes: nodes });
                    else if (mermaid.init) mermaid.init(undefined, nodes);
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
        var leaveFullscreen = function () {
            var element = document.fullscreenElement || document.webkitFullscreenElement;
            if (!element) return;
            var exit = document.exitFullscreen || document.webkitExitFullscreen;
            if (exit) { try { var result = exit.call(document); if (result && result.catch) result.catch(function () {}); } catch (e) { /* ignore */ } }
        };
        document.addEventListener("fullscreenchange", leaveFullscreen);
        document.addEventListener("webkitfullscreenchange", leaveFullscreen);
        var videos = document.querySelectorAll(".quiz-container video");
        Array.prototype.forEach.call(videos, function (video) {
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
        // Without onRequest the result was rendered for a POST: keep it in this browser like the server would,
        // and switch the address to ?result, so a refresh shows the same result instead of sending the answers again
        var token = box.getAttribute("data-result-token");
        if (token) {
            var secure = window.location.protocol === "https:" ? "; Secure" : "";
            document.cookie = "yquizr_" + id + "=" + token + "; path=" + window.location.pathname + "; max-age=86400; SameSite=Lax" + secure;
            if (window.history && history.replaceState) history.replaceState(null, "", window.location.pathname + "?result");
        }
        storeDel(PREFIX + id);
        storeDel(PREFIX + "seen:" + id);
        setCookie("yquiz_" + id, "", 0);
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
            '<div class="quiz-modal-step" data-step="confirm" hidden>' +
            '<p class="quiz-modal-ask">' + esc(t.certConfirm) + '</p>' +
            '<p class="quiz-modal-name"></p>' +
            '<p class="quiz-modal-note">' + esc(t.certWarning) + '</p>' +
            '<div class="quiz-modal-actions">' +
            '<button type="button" class="quiz-btn quiz-btn-quiet" data-back>' + esc(t.certEdit) + '</button>' +
            '<button type="button" class="quiz-btn" data-create>' + esc(t.certConfirmYes) + '</button>' +
            '</div></div>' +
            '<div class="quiz-modal-step" data-step="done" hidden>' +
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
        try { return JSON.parse(text); } catch (e) { /* page was rendered, look for the embedded answer */ }
        var match = text.match(/<script type="application\/json" class="quiz-cert-response">([\s\S]*?)<\/script>/);
        if (match) { try { return JSON.parse(match[1]); } catch (e) { /* ignore */ } }
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

        if (typeof d.pct === "number") {
            y += 74;
            c.fillStyle = muted;
            var mastery = (t.certMastery || "").replace("@percent", num(d.pct));
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

        // bottom row: date (left), score medallion (centre), certificate number with stamp (right)
        footerItem(c, W * 0.22, formatDate(d.date), t.certDate || "Date", ink, muted, sans);
        var mx = cx, my = 1385;
        c.fillStyle = "#ffffff"; c.beginPath(); c.arc(mx, my, 118, 0, Math.PI * 2); c.fill();
        c.strokeStyle = diagonal(); c.lineWidth = 10; c.beginPath(); c.arc(mx, my, 118, 0, Math.PI * 2); c.stroke();
        c.strokeStyle = diagonal(); c.lineWidth = 2; c.beginPath(); c.arc(mx, my, 100, 0, Math.PI * 2); c.stroke();
        c.fillStyle = ink; fit(c, num(d.score), "700", serif, 84, 40, 170); c.fillText(num(d.score), mx, my + 14);
        c.fillStyle = muted; c.font = "600 30px " + sans; c.fillText(t.certScore || "Score", mx, my + 60);
        var nx = W * 0.78;
        drawStamp(c, nx + 290, 1392, 100, String(d.site || ""), String(d.date || "").slice(0, 4), teal, violet, sans); // right of the number, never over it
        footerItem(c, nx, String(d.code || ""), t.certNumber || "No.", ink, muted, sans);

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

    // Round ink stamp with the site name around the edge
    function drawStamp(c, x, y, r, site, year, teal, violet, sans) {
        c.save();
        c.translate(x, y);
        c.rotate(-0.21);
        c.globalAlpha = 0.82;
        var ink = c.createLinearGradient(-r, -r, r, r); ink.addColorStop(0, teal); ink.addColorStop(1, violet);
        c.strokeStyle = ink; c.fillStyle = ink;
        c.lineWidth = 7; c.beginPath(); c.arc(0, 0, r, 0, Math.PI * 2); c.stroke();
        c.lineWidth = 2.5; c.beginPath(); c.arc(0, 0, r - 16, 0, Math.PI * 2); c.stroke();
        c.lineWidth = 2.5; c.beginPath(); c.arc(0, 0, r - 58, 0, Math.PI * 2); c.stroke();
        // site name written around the ring, repeated with stars to close the circle
        var label = (site || "").toUpperCase().trim() || "CERTIFIED";
        var radius = r - 37, fontSize = 26, circumference = 2 * Math.PI * radius;
        c.font = "700 " + fontSize + "px " + sans;
        while (c.measureText(label + " ★ ").width > circumference * 0.95 && fontSize > 14) {
            fontSize -= 1; c.font = "700 " + fontSize + "px " + sans;
        }
        while (c.measureText(label + " ★ ").width > circumference * 0.95 && label.length > 4) label = label.slice(0, -2).trim() + "…";
        var unit = label + " ★ ", text = unit;
        while (c.measureText(text + unit).width <= circumference * 0.97) text += unit;
        var total = c.measureText(text).width, angle = -Math.PI / 2 - (total / radius) / 2;
        c.textAlign = "center"; c.textBaseline = "middle";
        for (var i = 0; i < text.length; i++) {
            var w = c.measureText(text[i]).width;
            angle += (w / 2) / radius;
            c.save(); c.rotate(angle + Math.PI / 2); c.fillText(text[i], 0, -radius); c.restore();
            angle += (w / 2) / radius;
        }
        // centre: star and year
        starShape(c, 0, -8, 26, 11);
        c.font = "700 22px " + sans; c.fillText(year || "", 0, 34);
        c.restore();
        c.textAlign = "center"; c.textBaseline = "alphabetic";
    }
    function starShape(c, x, y, outer, inner) {
        c.beginPath();
        for (var i = 0; i < 10; i++) {
            var r = i % 2 ? inner : outer, a = -Math.PI / 2 + i * Math.PI / 5;
            c[i ? "lineTo" : "moveTo"](x + r * Math.cos(a), y + r * Math.sin(a));
        }
        c.closePath(); c.fill();
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
