<?php
// Quiz extension (customised build), based on YellowQuiz 0.9.1
// - Settings per quiz in the quiz file: time, penalty, shuffle, review, open, close; certificate requirements per category: "@ Name | pass: 80"
// - Categories with "@ Name"; score is always 0-100, every question has the same value
// - Floating boxes "[box]" (content) "[split]" (its questions) "[/box]"; headings separate the questions for shuffling
// - Essay questions ("essay: 100-150", not graded) and notes of a question ("{0: …}", "{1: …}") for the answer review
// - Every attempt gets a token signed by the server (random seed + start time)
// - Answer values are tokens bound to the attempt, the page never reveals the key; the order of questions and options
//   is keyed with the secret of the server, so it cannot be computed from the seed
// - After submitting, the signed result is kept in a cookie of the student's browser and shown at "?result"
// - The time limit is checked by the server, not only by the browser; an attempt can be submitted once
// - Stored on the server: certificates and essay answers, one CSV file per quiz in a closed folder, deleted automatically;
//   an anonymous code of every submitted attempt (no name), deleted at most 8 days after the start of the attempt
// - [quizdata]: the stored files, read only, for logged-in editors listed in QuizDataUsers

class YellowQuiz {
    const VERSION = "0.9.1-custom.24";
    const GRACE = 60;                 // seconds accepted after the time limit (network delay, auto-submit)
    const MAX_LIFETIME = 604800;      // 7 days, longest time an attempt can stay open
    const CERT_WINDOW = 86400;        // a certificate can be created up to 1 day after submitting
    const SWEEP_INTERVAL = 3600;      // expired certificate records are removed at most once per hour
    const FILE_MAX = 5242880;         // 5 MB limit for one quiz record file
    const MAX_QUESTIONS = 300;        // answers of at most 300 questions fit into the result kept by the browser
    const ESSAY_DEFAULT = 300;        // words of an essay answer without own limit
    const ESSAY_MAX = 2000;           // highest word limit of an essay answer
    const ESSAY_CHARS = 20000;        // characters kept of one essay answer, however long it is
    const SUBMITTED_FILE = "submitted-attempts.txt"; // codes of submitted attempts, in the data folder
    const DATA_MARKER = "<!-- Required by the quiz extension. Do not delete or edit this file.\n     It is the lock file and the cleanup timer of the certificate and essay data in this folder. -->\n";
    public $yellow;                   // access to API
    private $secret = null;
    private $certResponse = null;
    private $keepDays = null;
    private $linkMode = "tab";        // links in quiz text: "tab" (open in a new tab) or "text" (shown as plain text, on the quiz page)
    private $assets = "";             // quiz.css and quiz.js: "needed" by a quiz on this page, "sent" when they are on the page

    // Texts sent to quiz.js, per kind of page (the name "certHeading" reads the text "QuizCertHeading")
    const TEXTS_FORM = array("submitting", "unansweredTitle", "unansweredOne", "unansweredMany", "unansweredMore", "unansweredHint",
        "unansweredBack", "unansweredBadge", "awayWarning", "awaySubmitted", "timeUp", "closingSoon", "closedRunning",
        "closingSoonNoLimit", "closedRunningNoLimit", "essayWords", "essayTooShort", "essayTooLong", "essayNoPaste", "essayTimeLeft",
        "show", "hide", "boxEnd", "mediaPlay", "mediaPause", "mediaPlaying", "mediaPaused", "rotateRunning");
    const TEXTS_INTRO = array("introLast", "introViewLast", "preparing", "forgetConfirm", "forgetRemove", "forgetCancel", "rotateStart");
    const TEXTS_CERTIFICATE = array("certHeading", "certIntro", "certCompleted", "certMastery", "certParts", "certScore",
        "certDate", "certNumber", "certError");
    const TEXTS_RESULT = array("forgetConfirm", "forgetRemove", "forgetCancel", "close", "modalTitle", "namePrompt", "namePlaceholder",
        "certWarning", "certContinue", "certConfirm", "certEdit", "certConfirmYes", "certIssued", "certKept", "certUsed",
        "certExpired", "storageError", "show", "hide", "boxEnd", "mediaPlay", "mediaPause", "mediaPlaying", "mediaPaused");
    const TEXTS_REPRINT = array("reprintDone", "reprintNotFound", "reprintInvalid");
    const TEXTS_DATA = array("dataFile", "dataQuiz", "dataRows", "dataLast", "dataSize", "dataKind", "dataKindBase", "dataKindEssays",
        "dataKindOther", "dataBack", "dataRefresh", "dataUpdated", "dataDownload", "dataSearch", "dataTechnical", "dataCount", "dataMean",
        "dataMedian", "dataMin", "dataMax", "dataCertified", "dataShowEssays", "dataHideEssays", "dataQuestion", "dataWords", "dataLimitMax",
        "dataLimitRange", "dataOver", "dataUnder", "dataAutoTime", "dataAutoAway", "dataLate", "dataPage", "dataPrev", "dataNext", "dataNone",
        "dataEmpty", "dataNoAnswer", "dataColIssued", "dataColCertificate", "dataColName", "dataColDevice", "dataColMinutes", "dataColScore",
        "dataColTitle", "dataColAttempt", "dataColParts", "dataColIdentity", "dataColEssays", "dataSortHint");

    // Handle initialisation
    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $this->yellow->system->setDefault("quizDirectory", "content/.quiz/");
        $this->yellow->system->setDefault("quizCertificateKeepDays", "30");
        $this->yellow->system->setDefault("quizSecret", "");
        $this->yellow->system->setDefault("quizDataDirectory", "");
        $this->yellow->system->setDefault("quizDataUsers", "");
        $this->yellow->system->setDefault("quizMermaidUrl", "https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js");
        $this->yellow->system->setDefault("quizChartUrl", "https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js");
        $this->yellow->language->setDefaults(array(
            "Language: en",
            "QuizCorrected: Here is the corrected quiz: right answers are highlighted in <b>bold</b>, wrong answers in <del class=\"quiz-error\">red strikethrough</del>. Your score is shown above.",
            "QuizButton: Correction and score",
            "QuizResult: Right answers: <b>@right_answers out of @curr_question</b>",
            "QuizScore: Score: <b>@score out of @max_score</b>",
            "QuizTrue: True",
            "QuizFalse: False",
            "QuizCertButton: Get your certificate",
            "QuizModalTitle: Quiz completed",
            "QuizNamePrompt: Type your full name (not a nickname or initials) exactly as it should appear on the certificate.",
            "QuizNamePlaceholder: Full name",
            "QuizClose: Close",
            "QuizCertHeading: Certificate of Completion",
            "QuizCertIntro: This is to certify that",
            "QuizCertCompleted: has successfully completed a quiz on",
            "QuizCertScore: Score",
            "QuizCertDate: Date",
            "QuizCertNumber: Certificate No.",
            "QuizTimeUp: Time is up. Your answers are being submitted…",
            "QuizCertContinue: Continue",
            "QuizCertWarning: The certificate can be created only once for this attempt, and the name cannot be changed afterwards.",
            "QuizCertConfirm: Create the certificate for this name?",
            "QuizCertConfirmYes: Yes, create certificate",
            "QuizCertEdit: Change name",
            "QuizCertIssued: Certificate issued to @name",
            "QuizCertUsed: The certificate for this attempt has already been issued to @name. Retake the quiz to get a new one.",
            "QuizCertError: The certificate could not be created. Check your connection and try again.",
            "QuizRetake: Retake quiz",
            "QuizNotAnswered: Not answered",
            "QuizCorrectedMarks: Here are your answers: correct ones are marked in green and wrong ones in red. The correct options are not shown.",
            "QuizStorageError: The certificate cannot be saved because the server cannot write data. Please contact the site administrator.",
            "QuizCertMastery: demonstrating @score% mastery of the material",
            "QuizTooLong: This quiz has more than @max questions. Please split it into smaller quizzes.",
            "QuizCertParts: Mastery by part: @list.",
            "QuizCategoryRequired: ✓ and ✕ mark the parts that count for the certificate.",
            "QuizGroupOne: Question @first",
            "QuizGroupMany: Questions @first–@last",
            "QuizCertNeedHead: Your certificate still needs:",
            "QuizCertNeedRight: @name: @right of @total right — at least @needed needed",
            "QuizCertNeedPercent: @name: @percent% — at least @pass% needed (@count more right answers)",
            "QuizCertNeedPercentOne: @name: @percent% — at least @pass% needed (1 more right answer)",
            "QuizPenaltyNone: Penalty: none (no wrong answers).",
            "QuizPenaltyOne: Penalty: −@points for 1 wrong answer.",
            "QuizPenaltyMany: Penalty: −@points for @count wrong answers.",
            "QuizAwayWarning: You left the quiz page for @seconds seconds (@count of 3). After the third time, your answers are submitted automatically.",
            "QuizAwaySubmitted: Your answers were submitted automatically because you left the quiz page 3 times.",
            "QuizAwayCount: You left the quiz page @count times for 10 seconds or more.",
            "QuizJsRequired: Please enable JavaScript to take this quiz.",
            "QuizUnansweredTitle: Not all questions are answered",
            "QuizUnansweredOne: 1 question is not answered yet:",
            "QuizUnansweredMany: @count questions are not answered yet:",
            "QuizUnansweredMore: and @count more",
            "QuizUnansweredHint: Answer every question before you submit. Tap a number to go to that question.",
            "QuizUnansweredBack: Back to the questions",
            "QuizUnansweredBadge: Not answered yet",
            "QuizSubmitting: Checking your answers…",
            "QuizIntroQuestionsLabel: Questions",
            "QuizIntroTimeLabel: Time",
            "QuizIntroPenaltyLabel: Penalty",
            "QuizIntroPartsLabel: Parts",
            "QuizIntroCertificateLabel: Certificate",
            "QuizIntroPenaltyOff: None",
            "QuizIntroPenaltyOn: Wrong answers lower the score (correction for guessing)",
            "QuizIntroNoCertificate: No certificate for this quiz",
            "QuizIntroPartRight: @name: at least @needed of @total right",
            "QuizIntroPartPercent: @name: at least @pass%",
            "QuizIntroPartOpen: @name: no minimum",
            "QuizIntroPartFree: @name: not required",
            "QuizIntroReprintAsk: Need an earlier certificate again?",
            "QuizIntroReprintOpen: Reprint it",
            "QuizIntroBack: Back",
            "QuizIntroMinutes: @minutes minutes",
            "QuizIntroOneMinute: 1 minute",
            "QuizIntroNoTime: No time limit",
            "QuizIntroLeave: Leaving this page for 10 seconds or more is counted. The third time, your answers are submitted automatically.",
            "QuizIntroStart: Start quiz",
            "QuizIntroNotNow: Not now",
            "QuizIntroLast: Your last score on this quiz: @score (@date)",
            "QuizIntroViewLast: View last result",
            "QuizPreparing: Preparing your quiz…",
            "QuizCertKept: You already have a certificate for this quiz with a higher or equal score (@score). That certificate has been downloaded.",
            "QuizClosingSoon: This quiz closes to new attempts at @time. You can continue this attempt until your own time runs out.",
            "QuizClosedRunning: This quiz is now closed to new attempts. You can continue this attempt until your own time runs out.",
            "QuizClosingSoonNoLimit: This quiz closes to new attempts at @time. You can continue this attempt until @end. At that time, your answers are sent automatically.",
            "QuizClosedRunningNoLimit: This quiz is now closed to new attempts. You can continue this attempt until @end. At that time, your answers are sent automatically.",
            "QuizForget: Remove my quiz data from this browser",
            "QuizForgetConfirm: Remove your results for this quiz from this browser? If you have not created your certificate yet, you can no longer create it from this attempt.",
            "QuizForgetRemove: Remove",
            "QuizForgetCancel: Cancel",
            "QuizIntroOpensLabel: Opens",
            "QuizIntroClosesLabel: Closes",
            "QuizIntroNotYetOpen: This quiz opens on @date.",
            "QuizIntroClosedNow: This quiz closed on @date. It can no longer be started.",
            "QuizBoardRank: No.",
            "QuizBoardName: Name",
            "QuizBoardScore: Score",
            "QuizBoardDate: Date",
            "QuizBoardEmpty: No certificates yet.",
            "QuizTimeLeft: Time left",
            "QuizAnsweredLabel: answered",
            "QuizCertExpired: The time to create a certificate for this attempt has passed.",
            "QuizReprintTitle: Reprint a certificate",
            "QuizReprintPrompt: Enter the certificate number printed on your certificate.",
            "QuizReprintKeep: Certificates can be reprinted for @days days after they were created.",
            "QuizReprintKeepForever: Certificates can be reprinted at any time.",
            "QuizReprintLabel: Certificate number",
            "QuizReprintPlaceholder: e.g. 7FE2CB6E32",
            "QuizReprintButton: Reprint PDF",
            "QuizReprintDone: The certificate for @name has been downloaded.",
            "QuizReprintNotFound: No certificate with this number was found. It may have been deleted because its storage period has ended.",
            "QuizReprintInvalid: A certificate number has 10 characters (letters A–F and digits).",
            "QuizLate: The time limit had already passed when the answers were submitted. No certificate is available for this attempt.",
            "QuizInvalid: This attempt is not valid or has expired. Please retake the quiz.",
            "QuizSubmittedBefore: This attempt has already been submitted. Its result is shown only in the browser where it was submitted.",
            "QuizSetupError: This quiz cannot be used yet: the secret key could not be created. Set QuizSecret in the system settings.",
            "QuizIntroEssayLabel: Essays",
            "QuizIntroEssayOne: 1 question, scored later by your teacher (not part of the score here)",
            "QuizIntroEssayMany: @count questions, scored later by your teacher (not part of the score here)",
            "QuizIntroNameLabel: Your full name",
            "QuizIntroNameHint: Not a nickname or initials. Your answers and your certificate use this name. It cannot be changed after the start.",
            "QuizIntroNameError: Type your full name to start the quiz.",
            "QuizAnsweringAs: Your answers are saved under the name @name.",
            "QuizEssayLimitMax: Write at most @max words.",
            "QuizEssayLimitRange: Write @min to @max words.",
            "QuizEssayWords: @count / @max words",
            "QuizEssayTooShort: Too short: @count of at least @min words",
            "QuizEssayTooLong: Too long: @count words, at most @max",
            "QuizEssayNoPaste: Pasting is not allowed here. Type your own answer.",
            "QuizEssayTimeLeft: @time left",
            "QuizShow: Show",
            "QuizHide: Hide",
            "QuizBoxEnd: End",
            "QuizMediaPlay: Play",
            "QuizMediaPause: Pause",
            "QuizMediaPlaying: Playing @time",
            "QuizMediaPaused: Paused @time",
            "QuizRotateRunning: Turn your phone upright. The timer keeps running.",
            "QuizRotateStart: Turn your phone upright to start.",
            "QuizEssaySaved: Your essay answers were saved. Your teacher will score them.",
            "QuizEssayNotStored: Your essay answers of this attempt are not stored. If you have just sent them, please tell your teacher.",
            "QuizEssayNotShown: Your answer of this attempt is not stored.",
            "QuizEssayReviewOne: 1 word · scored by your teacher",
            "QuizEssayReviewMany: @count words · scored by your teacher",
            "QuizCertDownload: Download certificate",
            "QuizCertFor: Certificate issued to @name.",
            "QuizCertBest: Your certificate keeps your best score (@score).",
            "QuizDataUnsafe: Certificates and essay answers cannot be stored: the quiz folder (QuizDirectory) is not inside the content or system folder of Yellow, so a data folder in it could be opened from the web. Move the quiz folder to content/.quiz/ or set QuizDataDirectory. Only logged-in editors see this note.",
            "QuizDataTitle: Quiz data",
            "QuizDataLogin: The quiz data is shown only to editors who are logged in. Log in to the editor of this site and open this page there.",
            "QuizDataOpenEditor: Open this page in the editor",
            "QuizDataNoUsers: No one may see the quiz data yet. Add your e-mail address to QuizDataUsers in yellow-system.ini.",
            "QuizDataNotAllowed: The account @email may not see the quiz data. Add it to QuizDataUsers in yellow-system.ini.",
            "QuizDataNoFolder: There is no quiz data yet.",
            "QuizDataNoScript: Please enable JavaScript to see the data table.",
            "QuizDataKeep: Rows are deleted automatically @days days after their last change (QuizCertificateKeepDays).",
            "QuizDataKeepForever: Rows are kept until they are deleted by hand (QuizCertificateKeepDays: 0).",
            "QuizDataFile: File",
            "QuizDataQuiz: Quiz",
            "QuizDataRows: Rows",
            "QuizDataLast: Last entry",
            "QuizDataSize: Size",
            "QuizDataKind: Content",
            "QuizDataKindBase: Certificates",
            "QuizDataKindEssays: Certificates and essays",
            "QuizDataKindOther: Other columns, not used by the quiz",
            "QuizDataBack: Back to the list",
            "QuizDataRefresh: Refresh data",
            "QuizDataUpdated: Updated @time",
            "QuizDataDownload: Download CSV",
            "QuizDataSearch: Search name or certificate number",
            "QuizDataTechnical: Show technical columns",
            "QuizDataCount: Rows",
            "QuizDataMean: Mean",
            "QuizDataMedian: Median",
            "QuizDataMin: Lowest",
            "QuizDataMax: Highest",
            "QuizDataCertified: With certificate",
            "QuizDataShowEssays: Show essays",
            "QuizDataHideEssays: Hide essays",
            "QuizDataQuestion: Question @no",
            "QuizDataWords: @count words",
            "QuizDataLimitMax: limit: at most @max words",
            "QuizDataLimitRange: limit: @min to @max words",
            "QuizDataOver: over the limit",
            "QuizDataUnder: under the minimum",
            "QuizDataAutoTime: sent automatically: time was up",
            "QuizDataAutoAway: sent automatically: left the page 3 times",
            "QuizDataLate: sent after the time limit",
            "QuizDataPage: Page @page of @pages",
            "QuizDataPrev: Previous",
            "QuizDataNext: Next",
            "QuizDataNone: No rows match.",
            "QuizDataEmpty: This file has no rows.",
            "QuizDataNoAnswer: No answer",
            "QuizDataColIssued: Date",
            "QuizDataColCertificate: Certificate No.",
            "QuizDataColName: Name",
            "QuizDataColDevice: Device",
            "QuizDataColMinutes: Minutes",
            "QuizDataColScore: Score",
            "QuizDataColTitle: Quiz title",
            "QuizDataColAttempt: Attempt",
            "QuizDataColParts: Parts",
            "QuizDataColIdentity: Identity",
            "QuizDataColEssays: Essays",
            "QuizDataSortHint: Click a column title to sort.",
            "QuizExplanation: Explanation",
            "QuizNoteEssay: Line @line of the quiz file: notes are shown only for multiple-choice questions, so this note of an essay question is not shown. Only logged-in editors see this note.",
            "QuizEssayInvalid: Line @line of the quiz file: the essay setting \"@setting\" cannot be read, so this question uses the default limit of @max words. Only logged-in editors see this note.",
            "QuizNoticeSplitMissing: Line @line of the quiz file: [box] has no [split], so the content of the box ends before line @end, the first line that looks like a question. Only logged-in editors see this note.",
            "QuizNoticeBoxNoQuestions: Line @line of the quiz file: this box has no questions, because [split] or the questions below it are missing. Only logged-in editors see this note.",
            "QuizNoticeBoxEnd: Line @line of the quiz file: [box] is not closed with [/box], so the box ends before line @end. Only logged-in editors see this note.",
            "QuizNoticeBoxEndFile: Line @line of the quiz file: [box] is not closed with [/box], so the box ends at the end of the file. Only logged-in editors see this note.",
            "QuizNoticeBoxEmpty: Line @line of the quiz file: this box has no content, so it is not shown. Only logged-in editors see this note.",
            "QuizNoticeTagAlone: Line @line of the quiz file: @tag without [box] is not used. Only logged-in editors see this note.",
            "QuizNoticeSplitTwice: Line @line of the quiz file: a second @tag in the same box is not used. Only logged-in editors see this note.",
            "QuizNoticeTagLine: Line @line of the quiz file: [box], [split] and [/box] must stand alone on their line, so this line is shown as text. Only logged-in editors see this note.",
            "QuizNoticeNoteAlone: Line @line of the quiz file: this note is not directly under a question, so it is not shown. Only logged-in editors see this note.",
            "QuizNoticeMediaEnd: Line @line of the quiz file: <@tag> has no </@tag>, so it ends at line @end. Only logged-in editors see this note.",
            "QuizNoticeCodeEnd: Line @line of the quiz file: ``` has no closing ```, so the rest of the file is shown as code. Only logged-in editors see this note.",
            "QuizNoticeSetting: Line @line of the quiz file: the setting \"@setting\" is unknown or not valid, so the default is used. Only logged-in editors see this note.",
            "QuizNoticePass: Line @line of the quiz file: \"@text\" after the name of the category cannot be read, so this category has no pass mark. Write it as \"pass: 70\" (0 to 100). Only logged-in editors see this note.",
        ));
    }

    // Handle requests before the page is built: submissions, certificates, reprints
    public function onRequest($scheme, $address, $base, $location, $fileName) {
        if (!isset($_SERVER["REQUEST_METHOD"]) || $_SERVER["REQUEST_METHOD"]!="POST") return 0;
        $response = $this->getCertificateResponse();
        if ($response!==null) $this->sendJson($response);
        if (isset($_POST["quiz_id"], $_POST["quiz_attempt"]) || isset($_POST["quiz_start"])) {
            $url = isset($_POST["quiz_start"]) ? $this->getStartRedirect() : $this->getSubmissionRedirect();
            if ($url!="") {
                while (ob_get_level()>0) ob_end_clean();
                @header("Location: ".$url, true, 303);
                @header("Cache-Control: no-store");
                exit;
            }
        }
        return 0;
    }

    // Return address of the signed result for a valid submission, empty when the page must handle it
    private function getSubmissionRedirect() {
        $quizId = $this->getPost("quiz_id");
        if (!preg_match('/^[a-f0-9]{12}$/', $quizId) || $this->getSecret()=="" || $this->getPost("quiz_client")!=="1") return "";
        $attempt = $this->parseAttempt($quizId, $this->getPost("quiz_attempt"), 0, false);
        if (!$attempt) return "";
        $path = isset($_SERVER["REQUEST_URI"]) ? parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) : "";
        if (!is_string($path) || $path=="" || $path[0]!="/") return "";
        $claim = $this->claimAttempt($quizId, $attempt);
        if ($claim===false) return ""; // submitted before: the page says so
        $token = $this->createResult($quizId, $attempt);
        if ($this->getPost("quiz_meta")!=="") $this->storeEssays($quizId, $attempt, $token, $claim); // the result page tells whether they were stored
        $this->sendResultCookies($quizId, $token, $path);
        return $path."?result";
    }

    // Answer of a certificate or reprint request, null when the request is neither; answered once per request,
    // also when a page holds several quizzes
    private function getCertificateResponse() {
        if ($this->certResponse===null) {
            if (isset($_POST["quiz_cert"])) $this->certResponse = $this->issueCertificate($_POST);
            elseif (isset($_POST["quiz_reprint"])) $this->certResponse = $this->reprintCertificate($_POST);
        }
        return $this->certResponse;
    }

    // Path of the quiz page for the result cookie; anything unusual falls back to "/"
    private function getCookiePath($path) {
        return is_string($path) && preg_match('#^/[A-Za-z0-9/_\-.%~]*$#', $path) ? $path : "/";
    }

    // The result stays in the browser of the student for 24 hours, only sent back to this quiz page.
    // The attempt cookie is removed: the attempt is finished.
    private function sendResultCookies($quizId, $token, $path) {
        if (headers_sent()) return;
        @header("Set-Cookie: yquizr_".$quizId."=".$token."; Path=".$this->getCookiePath($path)."; Max-Age=86400".$this->getCookieFlags(), false);
        @header("Set-Cookie: yquiz_".$quizId."=; Path=/; Max-Age=0".$this->getCookieFlags(), false);
        if (isset($_COOKIE["yquizn_".$quizId])) @header("Set-Cookie: yquizn_".$quizId."=; Path=/; Max-Age=0".$this->getCookieFlags(), false);
    }

    private function getCookieFlags() {
        $secure = isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"]!=="" && $_SERVER["HTTPS"]!=="off";
        return "; SameSite=Lax".($secure ? "; Secure" : "");
    }

    private function sendJson($response) {
        while (ob_get_level()>0) ob_end_clean();
        @header("Content-Type: application/json; charset=utf-8");
        @header("Cache-Control: no-store");
        @header("X-Content-Type-Options: nosniff");
        echo json_encode($response, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Handle page content element
    public function onParseContentElement($page, $name, $text, $attributes, $type) {
        $output = null;
        if (preg_match('/^quiz(leaderboard|data|certificate)?$/', $name) && ($type=="block" || $type=="inline") && $this->assets=="") $this->assets = "needed";
        if ($name=="quizleaderboard" && ($type=="block" || $type=="inline")) return $this->renderLeaderboard($text);
        if ($name=="quizdata" && ($type=="block" || $type=="inline")) return $this->renderDataPage();
        if (($name=="quiz" || $name=="quizcertificate") && ($type=="block" || $type=="inline")) {
            $response = $this->getCertificateResponse();
            if ($response!==null) { // the editor of Yellow handles its own requests (onRequest is not called) and removes scripts
                return "<div class=\"quiz-cert-response\" hidden data-response=\"".$this->e($this->toJson($response))."\"></div>\n";
            }
            if ($this->getSecret()=="") return $this->renderNotice("", $this->text("quizSetupError"));
            $this->sweepRecords();
            if ($name=="quizcertificate") return "<div lang=\"en\" class=\"quiz-container\">\n".$this->renderReprint(false)."</div>\n";
            list($fileName, $customTitle) = $this->yellow->toolbox->getTextArguments($text); // "-" or nothing: no own title
            $lines = $this->readQuizFile($fileName);
            if (!$lines) return null;
            $quiz = $this->parseQuiz($lines);
            $count = count($quiz["questions"]);
            if (!$count) return null;
            if ($count>self::MAX_QUESTIONS) return $this->renderNotice("", str_replace("@max", (string)self::MAX_QUESTIONS, $this->text("quizTooLong")));
            $settings = $quiz["settings"];
            $quizId = substr(md5($this->yellow->page->location."|".$fileName), 0, 12);
            $title = $customTitle!="" ? $customTitle : $this->yellow->page->get("title");
            $recordKey = $this->getRecordKey($fileName, $quizId);
            $this->yellow->page->setHeader("Cache-Control", "no-store, max-age=0");
            if (isset($_GET["result"])) { // result page: only shown in the browser that holds the signed result
                $cookieName = "yquizr_".$quizId;
                $data = isset($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName]) ? $this->parseResult($quizId, $_COOKIE[$cookieName]) : null;
                if ($data) return $this->renderResult($quiz, $quizId, $recordKey, $data, $title, "");
            }
            $meta = $quiz["hasEssays"] ? $this->getQuizMeta($quizId, $fileName, $recordKey, $title) : null;
            if ($this->getPost("quiz_id")===$quizId && isset($_POST["quiz_start"])) {
                // "Start quiz" with a name, not redirected by onRequest: in the editor of Yellow, or without a name
                $name = $this->cleanName($this->getPost("quiz_name"));
                if ($meta && $name!="" && $this->getScheduleState($settings)=="open") {
                    $attempt = $this->startEssayAttempt($quizId, $quiz, $name);
                    $output = $this->renderForm($quiz, $quizId, $attempt, $meta, $this->getAttemptName($quizId, $attempt));
                } else {
                    $output = $this->renderIntro($quiz, $quizId, $title, $meta, $name=="");
                }
            } elseif ($this->getPost("quiz_id")===$quizId) { // onRequest did not redirect (in the editor of Yellow)
                if ($this->getPost("quiz_client")!=="1") return $this->renderNotice($quizId, $this->text("quizJsRequired"));
                $attempt = $this->parseAttempt($quizId, $this->getPost("quiz_attempt"), $settings["time"], false);
                if (!$attempt) return $this->renderInvalid($quizId);
                $claim = $this->claimAttempt($quizId, $attempt);
                if ($claim===false) return $this->renderInvalid($quizId, $this->text("quizSubmittedBefore"));
                $token = $this->createResult($quizId, $attempt); // quiz.js stores it in the browser like the redirect would
                if ($meta) $this->storeEssays($quizId, $attempt, $token, $claim);
                $output = $this->renderResult($quiz, $quizId, $recordKey, $this->parseResult($quizId, $token), $title, $token);
            } else {
                // A running attempt continues (refresh, second tab, reopened tab). Without one, only the
                // start popup is sent: no questions and no start time until "Start quiz" is pressed.
                $attempt = null;
                $cookieName = "yquiz_".$quizId;
                $retake = $this->getQuery("quiz_retake")===$quizId;
                if (!$retake && isset($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName])) {
                    $attempt = $this->parseAttempt($quizId, $_COOKIE[$cookieName], $settings["time"], true);
                }
                // "Start quiz" pressed: a new attempt, unless one is running (Start pressed twice) or the quiz is not open.
                // A quiz with essay questions starts only with a name (sent by POST, see above).
                if (!$retake && isset($_GET["quiz_start"]) && !$attempt && !$meta && $this->getScheduleState($settings)=="open") {
                    $attempt = $this->newAttempt($quizId);
                    $this->sendAttemptCookie($quizId, $attempt, $settings["time"]);
                }
                $name = $attempt && $meta ? $this->getAttemptName($quizId, $attempt) : "";
                if ($attempt && (!$meta || $name!="")) $output = $this->renderForm($quiz, $quizId, $attempt, $meta, $name);
                else $output = $this->renderIntro($quiz, $quizId, $title, $meta, false);
            }
        }
        return $output;
    }

    // Read quiz file: only plain relative names of .txt files inside the quiz directory, never from the data folder
    private function readQuizFile($fileName) {
        if (!$this->isQuizFileName($fileName)) return false;
        $directory = realpath($this->yellow->system->get("quizDirectory"));
        $path = realpath($this->yellow->system->get("quizDirectory").$fileName);
        if ($directory===false || $path===false || !is_file($path)) return false;
        if (strpos($path, $directory.DIRECTORY_SEPARATOR)!==0) return false;
        $data = $this->getDataPath();
        $data = $data!==false ? realpath($data) : false;
        if ($data!==false && strpos($path, $data.DIRECTORY_SEPARATOR)===0) return false;
        return @file($path);
    }

    private function isQuizFileName($fileName) {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-.\/]*\.txt$/i', $fileName) && strpos($fileName, "..")===false;
    }

    // Parse quiz file: settings, popup instructions, header text and categories. A category holds blocks in the order
    // of the file: text lines, headings, runs of questions and boxes. A box is written as "[box]" (its content) "[split]"
    // (the questions it accompanies) "[/box]". Questions are shuffled only inside their run: a heading, a box tag and
    // an "@" line end a run. Mistakes of the writer are kept as notices for logged-in editors.
    private function parseQuiz($lines) {
        $raw = $rawLines = array();
        $header = $questions = $categories = $instructions = $notices = $runs = $boxes = array();
        $tfStrings = array($this->text("quizTrue"), $this->text("quizFalse"));
        $current = -1;      // current category
        $run = null;        // open run of questions
        $pending = array(); // text after a question of the open run
        $unit = null;       // open box: its number, "content" or "questions", line of "[box]", first line that looks like a question
        $noteTarget = null; // question that the next note line ("{0: …}", "{1: …}") belongs to
        $hasMermaid = $hasChart = $hasEssays = false;
        $notice = function($key, $line, $values = array()) use (&$notices) {
            $notices[] = array($line, $key, $values);
        };
        $ensureCategory = function() use (&$categories, &$current) {
            if ($current>=0) return;
            $categories[] = array("name"=>"", "pass"=>null, "questions"=>array(), "blocks"=>array());
            $current = count($categories)-1;
        };
        // Blocks go to the open box after its "[split]", otherwise to the category
        $append = function($block) use (&$categories, &$boxes, &$current, &$unit) {
            if ($unit!==null && $unit["state"]=="questions") $boxes[$unit["box"]]["blocks"][] = $block;
            else $categories[$current]["blocks"][] = $block;
        };
        // Text after the last question of a run stays where it is
        $endRun = function() use (&$run, &$pending, $append) {
            if ($run!==null) foreach ($pending as $item) $append(array("line", $item));
            $run = null;
            $pending = array();
        };
        // Text between two questions travels with the question below it
        $addText = function($item) use (&$run, &$pending, &$header, &$current, $append) {
            if ($run!==null) $pending[] = $item;
            elseif ($current<0) $header[] = $item;
            else $append(array("line", $item));
        };
        // The content of the box is complete: the box takes its place, its questions follow
        $endContent = function() use (&$unit, &$boxes, $append, $notice) {
            if (!count($boxes[$unit["box"]]["content"])) $notice("quizNoticeBoxEmpty", $unit["line"]);
            $append(array("box", $unit["box"]));
            $unit["state"] = "questions";
        };
        $closeUnit = function() use (&$unit, $endRun) {
            $endRun();
            $unit = null;
        };
        // "[split]" missing: the content ends before its first line that looks like a question, the reading goes on there
        $contentMistake = function($at) use (&$unit, &$boxes, $notice, $endContent, $closeUnit) {
            if ($unit["qline"]!==null) {
                $boxes[$unit["box"]]["content"] = array_slice($boxes[$unit["box"]]["content"], 0, $unit["qcount"]);
                $notice("quizNoticeSplitMissing", $unit["line"], array("@end"=>$unit["qline"]+1));
                $endContent();
                return $unit["qline"];
            }
            $notice("quizNoticeBoxNoQuestions", $unit["line"]);
            $endContent();
            $closeUnit();
            return $at;
        };
        $total = count($lines);
        $n = 0;
        while (true) {
            if ($n>=$total) {
                if ($unit===null) break;
                if ($unit["state"]=="content") {
                    $n = $contentMistake($total);
                    continue;
                }
                $notice("quizNoticeBoxEndFile", $unit["line"]);
                $closeUnit();
                continue;
            }
            $index = $n;
            $line = rtrim($lines[$n], "\r\n");
            $n++;
            if (trim($line)=="") continue;
            $inContent = $unit!==null && $unit["state"]=="content";
            if (preg_match('/^\s*\[(box|split|\/box)\]\s*$/i', $line, $matches)) {
                $tag = strtolower($matches[1]);
                $noteTarget = null;
                if ($tag=="box") {
                    if ($unit===null) {
                        $endRun();
                        $ensureCategory();
                        $boxes[] = array("content"=>array(), "blocks"=>array());
                        $unit = array("box"=>count($boxes)-1, "state"=>"content", "line"=>$n, "qline"=>null, "qcount"=>0);
                    } elseif ($inContent) {
                        $n = $contentMistake($index);
                    } else {
                        $notice("quizNoticeBoxEnd", $unit["line"], array("@end"=>$n));
                        $closeUnit();
                        $n = $index;
                    }
                } elseif ($tag=="split") {
                    if ($inContent) $endContent();
                    else $notice($unit===null ? "quizNoticeTagAlone" : "quizNoticeSplitTwice", $n, array("@tag"=>"[split]"));
                } elseif ($unit===null) {
                    $notice("quizNoticeTagAlone", $n, array("@tag"=>"[/box]"));
                } elseif ($inContent) {
                    $n = $contentMistake($index);
                    if ($unit===null) $n = $index+1;
                } else {
                    $closeUnit();
                }
                continue;
            }
            if ($unit!==null && $line[0]=="@") {
                if ($inContent) {
                    $n = $contentMistake($index);
                    continue;
                }
                $notice("quizNoticeBoxEnd", $unit["line"], array("@end"=>$n));
                $closeUnit();
                $n = $index;
                continue;
            }
            if (preg_match('/^\s*\[(box|split|\/box)\]/i', $line)) $notice("quizNoticeTagLine", $n);
            // "{0: …}", "{1: …}", "{2: …}": notes of the question just above, shown with the answer review. Any other line ends the notes.
            if (!$inContent && preg_match('/^\s*\{\s*(\d{1,3})\s*:(.*)\}\s*$/', $line, $matches)) {
                if ($noteTarget===null) {
                    $notice("quizNoticeNoteAlone", $n);
                } elseif ($questions[$noteTarget]["essay"]!==null) {
                    $notice("quizNoteEssay", $n);
                } elseif (trim($matches[2])!=="") {
                    $questions[$noteTarget]["notes"][] = array(intval($matches[1]), trim($matches[2]));
                }
                continue;
            }
            $noteTarget = null;
            $block = null;
            if (preg_match('/^\s*```\s*([A-Za-z0-9_\-]*)\s*$/', $line, $matches)) {
                $language = strtolower($matches[1]);
                $code = array();
                $start = $n;
                $closed = false;
                while ($n<$total) {
                    $inner = rtrim($lines[$n++], "\r\n");
                    if (preg_match('/^\s*```\s*$/', $inner)) {
                        $closed = true;
                        break;
                    }
                    $code[] = $inner;
                }
                if (!$closed) $notice("quizNoticeCodeEnd", $start);
                $kind = $language=="mermaid" ? "mermaid" : ($language=="chartjs" || $language=="chart" ? "chart" : "code");
                if ($kind=="mermaid") $hasMermaid = true;
                if ($kind=="chart") $hasChart = true;
                $block = "\x01".$kind."\x01".implode("\n", $code);
            } elseif (preg_match('/^\s*<(iframe|audio|video)\b/i', $line, $matches)) {
                // the code ends at its end tag; without one, at the next empty line or line of the quiz syntax
                $tag = strtolower($matches[1]);
                $html = $line;
                $start = $n;
                $complete = stripos($html, "</".$tag)!==false || substr(rtrim($html), -2)=="/>";
                for ($extra=0; !$complete && $n<$total && $extra<40; $extra++) {
                    $next = rtrim($lines[$n], "\r\n");
                    if (trim($next)=="" || preg_match('/^\s*[@#=!\[{]/', $next) || (preg_match('/^\d+\.\s/', $next) && strpos($next, "|")!==false)) break;
                    $html .= "\n".$next;
                    $n++;
                    $complete = stripos($html, "</".$tag)!==false;
                }
                if (!$complete) $notice("quizNoticeMediaEnd", $start, array("@tag"=>$tag, "@end"=>$n));
                $block = "\x01html\x01".$html;
            }
            $isQuestion = $block===null && preg_match('/^\d+\.\s/', $line) && strpos($line, "|")!==false;
            if ($inContent) { // the content of a box is shown as written
                if ($isQuestion && $unit["qline"]===null) {
                    $unit["qline"] = $index;
                    $unit["qcount"] = count($boxes[$unit["box"]]["content"]);
                }
                $boxes[$unit["box"]]["content"][] = $block!==null ? $block : $line;
                continue;
            }
            if ($block===null) {
                if ($line[0]=="=") {
                    $this->parseSettingLine(substr($line, 1), $n, $raw, $rawLines, $notice);
                    continue;
                }
                if ($line[0]=="!" && substr($line, 0, 2)!="![") { // popup instruction, "![" stays an image or media line
                    $text = trim(substr($line, 1));
                    if ($text!="") $instructions[] = $text;
                    continue;
                }
                if ($line[0]=="@") {
                    $endRun();
                    $head = explode("|", substr($line, 1), 2);
                    $name = trim($head[0]);
                    if ($name=="") continue;
                    $pass = isset($head[1]) ? $this->parseCategoryPass($head[1]) : null;
                    if (isset($head[1]) && trim($head[1])!="" && $pass===null) $notice("quizNoticePass", $n, array("@text"=>trim($head[1])));
                    $categories[] = array("name"=>$name, "pass"=>$pass, "questions"=>array(), "blocks"=>array());
                    $current = count($categories)-1;
                    continue;
                }
                if (preg_match('/^#{1,6}(\s|$)/', $line)) { // a heading separates the questions above it from those below it
                    $endRun();
                    $ensureCategory();
                    $append(array("line", $line));
                    continue;
                }
                if ($isQuestion) {
                    $parts = array_map("trim", explode("|", $line));
                    $question = preg_replace('/^\d+\.\s+/', "", $parts[0]);
                    $options = array_slice($parts, 1);
                    $isTrueFalse = count($options)==1 && ($options[0]=="1" || $options[0]=="0");
                    $essay = null;
                    if (count($options)==1 && preg_match('/^essay\b(.*)$/i', $options[0], $matches)) {
                        $essay = $this->parseEssaySetting($matches[1]);
                        if ($essay===null) {
                            $notice("quizEssayInvalid", $n, array("@setting"=>$options[0], "@max"=>self::ESSAY_DEFAULT));
                            $essay = array("min"=>0, "max"=>self::ESSAY_DEFAULT);
                        }
                        $options = array();
                        $hasEssays = true;
                    }
                    $trueFirst = true;
                    if ($isTrueFalse) { // correct option is always at index 0
                        $trueFirst = $options[0]=="1";
                        $options = $trueFirst ? $tfStrings : array_reverse($tfStrings);
                    }
                    $ensureCategory();
                    if ($run===null) {
                        $runs[] = array();
                        $run = count($runs)-1;
                        $append(array("run", $run));
                    }
                    $questions[] = array("text"=>$question, "options"=>$options, "tf"=>$isTrueFalse,
                        "trueFirst"=>$trueFirst, "pre"=>$pending, "category"=>$current, "essay"=>$essay, "notes"=>array());
                    $pending = array();
                    $index = count($questions)-1;
                    $runs[$run][] = $index;
                    $categories[$current]["questions"][] = $index;
                    $noteTarget = $index;
                    continue;
                }
            }
            $addText($block!==null ? $block : $line);
        }
        $endRun();
        $named = 0;
        foreach ($categories as $category) {
            if (count($category["questions"]) && $category["name"]!="") $named++;
        }
        // Questions before the first "@" line form the category "General" when other categories exist
        foreach ($categories as $index=>$category) {
            if ($category["name"]=="" && count($category["questions"]) && $named>0) $categories[$index]["name"] = "General";
        }
        $settings = $this->getSettings($raw, count($questions));
        foreach ($raw as $key=>$value) {
            if (!$this->isValidSetting($key, $value)) $notice("quizNoticeSetting", $rawLines[$key], array("@setting"=>$key.": ".$value));
        }
        // Notes in the order of their numbers; notes with the same number keep the order of the file
        foreach ($questions as $index=>$question) {
            $order = array();
            foreach ($question["notes"] as $position=>$note) $order[] = array($note[0], $position, $note[1]);
            sort($order);
            $questions[$index]["notes"] = array_map(function($item) { return array($item[0], $item[2]); }, $order);
        }
        // Questions that count for the score: every question except essays
        foreach ($categories as $index=>$category) {
            $categories[$index]["scored"] = array();
            foreach ($category["questions"] as $question) {
                if ($questions[$question]["essay"]===null) $categories[$index]["scored"][] = $question;
            }
        }
        // Notices in the order of the lines of the file; notices of one line in the order they were found
        array_multisort(array_column($notices, 0), SORT_NUMERIC, array_keys($notices), SORT_NUMERIC, $notices);
        return array("header"=>$header, "questions"=>$questions, "categories"=>$categories, "runs"=>$runs, "boxes"=>$boxes,
            "instructions"=>$instructions, "notices"=>$notices, "hasCategories"=>$named>0, "hasMermaid"=>$hasMermaid,
            "hasChart"=>$hasChart, "hasEssays"=>$hasEssays, "settings"=>$settings);
    }

    // Word limits of an essay question: "essay" (at most 300 words), "essay: 150" (at most 150), "essay: 100-150" (100 to 150).
    // At most 2000 words. Null when the setting cannot be read.
    private function parseEssaySetting($rest) {
        $rest = trim($rest);
        if ($rest=="") return array("min"=>0, "max"=>self::ESSAY_DEFAULT);
        if (preg_match('/^:\s*(\d{1,5})\s*$/', $rest, $matches)) {
            $min = 0;
            $max = intval($matches[1]);
        } elseif (preg_match('/^:\s*(\d{1,5})\s*[-\x{2013}]\s*(\d{1,5})\s*$/u', $rest, $matches)) {
            $min = intval($matches[1]);
            $max = intval($matches[2]);
        } else {
            return null;
        }
        return $max<1 || $max>self::ESSAY_MAX || $min>$max ? null : array("min"=>$min, "max"=>$max);
    }

    // Read one "=" line: "time: 50, penalty: 1"; a part without ":" is reported to editors
    private function parseSettingLine($text, $line, &$raw, &$rawLines, $notice) {
        foreach (explode(",", $text) as $part) {
            if (trim($part)=="") continue;
            $pair = explode(":", $part, 2);
            $key = strtolower(trim($pair[0]));
            $value = count($pair)==2 ? trim($pair[1]) : "";
            if ($key=="" || $value=="") {
                $notice("quizNoticeSetting", $line, array("@setting"=>trim($part)));
                continue;
            }
            $raw[$key] = $value;
            $rawLines[$key] = $line;
        }
    }

    // A setting that getSettings() uses as written
    private function isValidSetting($key, $value) {
        if ($key=="time") return preg_match('/^\d{1,5}$/', $value)==1;
        if ($key=="penalty" || $key=="shuffle") return $value==="0" || $value==="1";
        if ($key=="review") return $value==="0" || $value==="1" || strtolower($value)==="card";
        if ($key=="open" || $key=="close") return $this->parseDateTime($value)>0;
        return false;
    }

    // Certificate requirement of a category: "pass: 80" after the name, 0 to 100 (one decimal); null when missing or invalid
    private function parseCategoryPass($text) {
        if (!preg_match('/^\s*pass\s*:\s*(\d{1,3}(?:\.\d+)?)\s*$/i', $text, $matches)) return null;
        $value = floatval($matches[1]);
        return $value<=100 ? round($value, 1) : null;
    }

    // Turn raw settings into checked values, anything unknown or invalid falls back to the default
    private function getSettings($raw, $count) {
        $settings = array("time"=>$count, "penalty"=>0, "shuffle"=>1, "review"=>0, "open"=>0, "close"=>0);
        if (isset($raw["time"]) && preg_match('/^\d{1,5}$/', $raw["time"])) $settings["time"] = min(intval($raw["time"]), 10080);
        foreach (array("penalty", "shuffle", "review") as $key) {
            if (isset($raw[$key]) && ($raw[$key]==="0" || $raw[$key]==="1")) $settings[$key] = intval($raw[$key]);
        }
        if (isset($raw["review"]) && strtolower($raw["review"])==="card") $settings["review"] = 2;
        foreach (array("open", "close") as $key) {
            if (isset($raw[$key])) $settings[$key] = $this->parseDateTime($raw[$key]);
        }
        return $settings;
    }

    // "2026-10-10 08:00" (also "2026-10-10" or with seconds) in the time zone of the site (CoreTimezone), 0 when invalid
    private function parseDateTime($text) {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', trim($text), $m)) return 0;
        if (!checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) return 0;
        $hour = isset($m[4]) ? intval($m[4]) : 0;   // groups that did not match at the end are not set
        $minute = isset($m[5]) ? intval($m[5]) : 0;
        $second = isset($m[6]) ? intval($m[6]) : 0;
        if ($hour>23 || $minute>59 || $second>59) return 0;
        return mktime($hour, $minute, $second, intval($m[2]), intval($m[3]), intval($m[1]));
    }

    // "soon" before open, "closed" from close on, otherwise "open"
    private function getScheduleState($settings) {
        $now = time();
        if ($settings["open"]>0 && $now<$settings["open"]) return "soon";
        if ($settings["close"]>0 && $now>=$settings["close"]) return "closed";
        return "open";
    }

    // End of an attempt: start + time limit; without a time limit and with a closing time, 30 minutes after closing
    private function getDeadline($settings, $start) {
        if ($settings["time"]>0) return $start+$settings["time"]*60;
        if ($settings["close"]>0 && $start<$settings["close"]) return $settings["close"]+1800;
        return 0;
    }

    private function formatDateTime($time) {
        return date("j F Y, H:i", $time);
    }

    // The attempt is kept by the browser: after a refresh the server recognises it, keeps its start time and its order
    private function sendAttemptCookie($quizId, $attempt, $minutes) {
        if (headers_sent()) return;
        @header("Set-Cookie: yquiz_".$quizId."=".$attempt["token"]."; Path=/; Max-Age=".$this->getLifetime($minutes).$this->getCookieFlags(), false);
    }

    // Certificate requirements: the categories with "pass" (only named categories, only with questions).
    // Without any, the quiz has no certificate.
    private function getRequirements($quiz) {
        $requirements = array();
        if (!$quiz["hasCategories"]) return $requirements;
        foreach ($quiz["categories"] as $index=>$category) {
            if ($category["pass"]!==null && count($category["scored"])) $requirements[$index] = $category["pass"];
        }
        return $requirements;
    }

    // Fewest right answers that reach a pass mark in a category of $count questions (without wrong answers)
    private function getNeededRight($pass, $count) {
        for ($right=0; $right<=$count; $right++) {
            if ($this->getPercent($right, $count)>=$pass) return $right;
        }
        return $count;
    }

    // Start popup: title, facts from the settings (English), instructions from "!" lines, last score, reprint.
    // A quiz with essay questions asks for the full name before it starts.
    private function renderIntro($quiz, $quizId, $title, $meta, $nameError) {
        $settings = $quiz["settings"];
        $count = count($quiz["questions"]);
        $requirements = $this->getRequirements($quiz);
        $facts = array(); // label => value (or list of lines), shown as a plain two-column list
        $facts[$this->text("quizIntroQuestionsLabel")] = (string)$count;
        $facts[$this->text("quizIntroTimeLabel")] = $settings["time"]>0 ? ($settings["time"]==1 ? $this->text("quizIntroOneMinute") :
            str_replace("@minutes", $settings["time"], $this->text("quizIntroMinutes"))) : $this->text("quizIntroNoTime");
        if ($settings["open"]>0) $facts[$this->text("quizIntroOpensLabel")] = $this->formatDateTime($settings["open"]);
        if ($settings["close"]>0) $facts[$this->text("quizIntroClosesLabel")] = $this->formatDateTime($settings["close"]);
        $facts[$this->text("quizIntroPenaltyLabel")] = $settings["penalty"]==1 ? $this->text("quizIntroPenaltyOn") : $this->text("quizIntroPenaltyOff");
        if ($quiz["hasEssays"]) {
            $essays = 0;
            foreach ($quiz["questions"] as $question) if ($question["essay"]!==null) $essays++;
            $facts[$this->text("quizIntroEssayLabel")] = str_replace("@count", (string)$essays, $this->text($essays==1 ? "quizIntroEssayOne" : "quizIntroEssayMany"));
        }
        if ($quiz["hasCategories"]) {
            $names = array();
            foreach ($quiz["categories"] as $category) if (count($category["questions"])) $names[] = $category["name"];
            $facts[$this->text("quizIntroPartsLabel")] = implode(", ", $names);
        }
        if (!count($requirements)) {
            $certificate = $this->text("quizIntroNoCertificate");
        } else {
            $certificate = array();
            foreach ($quiz["categories"] as $index=>$category) {
                $total = count($category["scored"]);
                if (!$total) continue;
                if (!isset($requirements[$index])) $line = $this->text("quizIntroPartFree");
                elseif ($requirements[$index]<=0) $line = $this->text("quizIntroPartOpen");
                elseif ($settings["penalty"]==1) $line = $this->text("quizIntroPartPercent");
                else $line = $this->text("quizIntroPartRight");
                $pass = isset($requirements[$index]) ? $requirements[$index] : 0;
                $certificate[] = str_replace(array("@name", "@needed", "@total", "@pass"),
                    array($category["name"], (string)$this->getNeededRight($pass, $total), (string)$total, $this->formatNumber($pass)), $line);
            }
        }
        $facts[$this->text("quizIntroCertificateLabel")] = $certificate;
        $home = $this->yellow->page->base.$this->yellow->content->getHomeLocation($this->yellow->page->location);
        $start = $this->getPagePath()."?quiz_start=1";
        $state = $this->getScheduleState($settings);
        $output = "<div lang=\"en\" class=\"quiz-container quiz-intro quiz-protect\" id=\"quiz-{$quizId}\" data-quiz-id=\"{$quizId}\"";
        $output .= " data-i18n=\"".$this->e($this->toJson($this->getClientTexts(self::TEXTS_INTRO)))."\">\n";
        $output .= "<div class=\"quiz-intro-layer\">\n<div class=\"quiz-intro-card\" role=\"dialog\" aria-modal=\"true\" aria-labelledby=\"quiz-{$quizId}-title\">\n";
        $output .= "<div class=\"quiz-intro-main\">\n".$this->renderSetupNotices($quiz);
        // only div and span with quiz class names: themes often style or script h2, dl/dt/dd (accordions), ul/li
        $output .= "<div class=\"quiz-intro-title\" role=\"heading\" aria-level=\"2\" id=\"quiz-{$quizId}-title\">".$this->e($title)."</div>\n";
        $output .= "<div class=\"quiz-intro-facts\">";
        foreach ($facts as $label=>$value) {
            $shown = is_array($value) ? implode("", array_map(function($line) { return "<span class=\"quiz-intro-line\">".$this->e($line)."</span>"; }, $value)) : $this->e($value);
            $output .= "<div class=\"quiz-intro-fact\"><span class=\"quiz-intro-label\">".$this->e($label)."</span><span class=\"quiz-intro-value\">".$shown."</span></div>";
        }
        $output .= "</div>\n";
        if (count($quiz["instructions"])) {
            $output .= "<div class=\"quiz-intro-text\">\n";
            foreach ($quiz["instructions"] as $line) $output .= $this->renderLine($line)."\n";
            $output .= "</div>\n";
        }
        $output .= "<p class=\"quiz-intro-rule\">".$this->e($this->text("quizIntroLeave"))."</p>\n";
        $output .= "<p class=\"quiz-intro-last\" hidden></p>\n";
        if ($state=="soon") $output .= "<p class=\"quiz-intro-closed\">".$this->e(str_replace("@date", $this->formatDateTime($settings["open"]), $this->text("quizIntroNotYetOpen")))."</p>\n";
        if ($state=="closed") $output .= "<p class=\"quiz-intro-closed\">".$this->e(str_replace("@date", $this->formatDateTime($settings["close"]), $this->text("quizIntroClosedNow")))."</p>\n";
        $notNow = "<a class=\"quiz-btn quiz-btn-quiet quiz-notnow\" href=\"".$this->e($home)."\">".$this->e($this->text("quizIntroNotNow"))."</a>";
        if ($meta && $state=="open") {
            $nameId = "quiz-{$quizId}-name";
            $output .= "<form class=\"quiz-intro-nameform\" method=\"post\" action=\"".$this->e($this->getPagePath())."\" novalidate>\n";
            $output .= "<input type=\"hidden\" name=\"quiz_start\" value=\"1\" />\n<input type=\"hidden\" name=\"quiz_id\" value=\"{$quizId}\" />\n".$this->renderMetaFields($meta);
            $output .= "<label class=\"quiz-intro-namelabel\" for=\"{$nameId}\">".$this->e($this->text("quizIntroNameLabel"))."</label>\n";
            $output .= "<p class=\"quiz-intro-namehint\">".$this->e($this->text("quizIntroNameHint"))."</p>\n";
            $output .= "<input type=\"text\" class=\"quiz-intro-name\" id=\"{$nameId}\" name=\"quiz_name\" maxlength=\"60\" autocomplete=\"name\" spellcheck=\"false\"".
                " placeholder=\"".$this->e($this->text("quizNamePlaceholder"))."\"".($nameError ? " aria-invalid=\"true\"" : "")." />\n";
            $output .= "<p class=\"quiz-intro-nameerror\" role=\"alert\"".($nameError ? "" : " hidden").">".$this->e($this->text("quizIntroNameError"))."</p>\n";
            $output .= "<div class=\"quiz-intro-actions\"><button type=\"submit\" class=\"quiz-btn quiz-start\">".$this->e($this->text("quizIntroStart"))."</button>".$notNow."</div>\n";
            $output .= "</form>\n";
        } else {
            $output .= "<div class=\"quiz-intro-actions\">";
            if ($state=="open") $output .= "<a class=\"quiz-btn quiz-start\" href=\"".$this->e($start)."\" rel=\"nofollow\">".$this->e($this->text("quizIntroStart"))."</a>";
            $output .= $notNow."</div>\n";
        }
        $output .= "<p class=\"quiz-intro-foot\">".$this->e($this->text("quizIntroReprintAsk"))." <button type=\"button\" class=\"quiz-intro-link quiz-intro-reprint-open\">".$this->e($this->text("quizIntroReprintOpen"))."</button></p>\n";
        $output .= "<p class=\"quiz-intro-forget\" hidden><button type=\"button\" class=\"quiz-intro-link quiz-forget\">".$this->e($this->text("quizForget"))."</button></p>\n";
        $output .= "</div>\n";
        // second view of the same card: reprint a certificate by its number
        $output .= "<div class=\"quiz-intro-reprint\" hidden>\n";
        $output .= "<button type=\"button\" class=\"quiz-intro-link quiz-intro-back\">&larr; ".$this->e($this->text("quizIntroBack"))."</button>\n";
        $output .= "<div class=\"quiz-intro-subtitle\" role=\"heading\" aria-level=\"3\">".$this->e($this->text("quizReprintTitle"))."</div>\n";
        $output .= $this->renderReprint(true);
        $output .= "</div>\n</div>\n</div>\n</div>\n";
        return $output;
    }

    // Start a new attempt: random seed + start time, signed by the server
    private function newAttempt($quizId) {
        $seed = bin2hex(random_bytes(8));
        $start = time();
        $token = $seed.".".$start.".".substr($this->sign("attempt|".$quizId."|".$seed."|".$start), 0, 16);
        return array("token"=>$token, "seed"=>$seed, "start"=>$start);
    }

    // How long an attempt stays open: time limit + 15 minutes, or 24 hours without time limit, at most 7 days
    private function getLifetime($minutes) {
        return min(self::MAX_LIFETIME, $minutes>0 ? $minutes*60+900 : 86400);
    }

    // Check an attempt token, returns null when it is forged, from the future or (for the form) too old
    private function parseAttempt($quizId, $token, $minutes, $forForm) {
        if (!is_string($token) || !preg_match('/^([a-f0-9]{16})\.(\d{9,11})\.([a-f0-9]{16})$/', $token, $matches)) return null;
        $expected = substr($this->sign("attempt|".$quizId."|".$matches[1]."|".$matches[2]), 0, 16);
        if (!hash_equals($expected, $matches[3])) return null;
        $start = intval($matches[2]);
        $now = time();
        if ($start>$now+self::GRACE) return null;
        if ($forForm && $now>$start+$this->getLifetime($minutes)) return null;
        return array("token"=>$token, "seed"=>$matches[1], "start"=>$start);
    }

    // Signed result kept by the browser: seed.start.submitted.leaves.reason.answers.stamp
    // Every answer is the first 8 characters of its answer code, "--------" for no answer.
    // The stamp also covers the quiz, so a result can only be used on its own quiz.
    private function createResult($quizId, $attempt) {
        $answers = array();
        $posted = isset($_POST["quest"]) && is_array($_POST["quest"]) ? $_POST["quest"] : array();
        foreach ($posted as $index=>$value) {
            if (is_int($index) || ctype_digit((string)$index)) {
                $index = intval($index);
                if ($index<self::MAX_QUESTIONS && is_string($value) && preg_match('/^[a-f0-9]{16}$/', $value)) $answers[$index] = substr($value, 0, 8);
            }
        }
        $list = "";
        if (count($answers)) {
            for ($index=0; $index<=max(array_keys($answers)); $index++) $list .= isset($answers[$index]) ? $answers[$index] : "--------";
        }
        $away = preg_match('/^\d{1,2}$/', $this->getPost("quiz_away")) ? intval($this->getPost("quiz_away")) : 0;
        $auto = array("time"=>1, "away"=>2);
        $reason = isset($auto[$this->getPost("quiz_auto")]) ? $auto[$this->getPost("quiz_auto")] : 0;
        $payload = $attempt["seed"].".".$attempt["start"].".".time().".".$away.".".$reason.".".$list;
        return $payload.".".substr($this->sign("result|".$quizId."|".$payload), 0, 32);
    }

    // Code of an attempt for the server: certificate records and the list of submitted attempts. It contains no name.
    private function getNonce($quizId, $seed, $start) {
        return substr($this->sign("nonce|".$quizId."|".$seed."|".$start), 0, 20);
    }

    // An attempt is submitted once, the first submission is final. The data folder keeps the code of every submitted
    // attempt until neither the attempt nor its certificate can be used any more (at most 8 days after the start).
    // Returns true for the first submission, false for a second one (refused), null when nothing could be kept
    // (no data folder or it cannot be written): the submission is accepted, but it can get no certificate.
    private function claimAttempt($quizId, $attempt) {
        $directory = $this->getDataDirectory(true);
        $lock = $directory!==false ? $this->lockData($directory, true) : null;
        if (!$lock) return null;
        $file = $directory.self::SUBMITTED_FILE;
        $code = $this->getNonce($quizId, $attempt["seed"], $attempt["start"])." ";
        $content = is_file($file) ? @file_get_contents($file) : "";
        if (!is_string($content)) {
            $claim = null;
        } elseif (strpos("\n".$content, "\n".$code)!==false) {
            $claim = false;
        } else {
            $line = $code.($attempt["start"]+self::MAX_LIFETIME+self::CERT_WINDOW+self::GRACE)."\n";
            $claim = @file_put_contents($file, $line, FILE_APPEND)===strlen($line) ? true : null;
        }
        $this->unlockData($lock);
        return $claim;
    }

    // The first submission of the attempt with this code was kept: only such an attempt can get a certificate
    private function isSubmitted($nonce) {
        $directory = $this->getDataDirectory(false);
        $file = $directory!==false ? $directory.self::SUBMITTED_FILE : "";
        $content = $file!="" && is_file($file) ? @file_get_contents($file) : false;
        return is_string($content) && preg_match('/^'.preg_quote($nonce, "/").' (\d{9,11})$/m', $content, $matches)==1 && intval($matches[1])>=time();
    }

    // Check a signed result of this quiz, returns data, or null when it is forged, broken or from another quiz
    private function parseResult($quizId, $token) {
        if (!is_string($token) || strlen($token)>3000) return null;
        if (!preg_match('/^([a-f0-9]{16})\.(\d{9,11})\.(\d{9,11})\.(\d{1,2})\.([0-2])\.((?:[a-f0-9]{8}|-{8})*)\.([a-f0-9]{32})$/', $token, $matches)) return null;
        $payload = substr($token, 0, strrpos($token, "."));
        if (!hash_equals(substr($this->sign("result|".$quizId."|".$payload), 0, 32), $matches[7])) return null;
        $answers = array();
        foreach ($matches[6]!=="" ? str_split($matches[6], 8) : array() as $value) $answers[] = $value==="--------" ? "" : $value;
        return array("s"=>$matches[1], "t"=>intval($matches[2]), "u"=>intval($matches[3]),
            "w"=>intval($matches[4]), "x"=>intval($matches[5]), "a"=>$answers);
    }

    // Display order: everything keeps the order of the file, only the questions of a run are shuffled among themselves
    private function getOrder($quiz, $seed) {
        $order = array();
        foreach ($quiz["runs"] as $id=>$run) {
            $order[$id] = $quiz["settings"]["shuffle"] ? $this->shuffleSeeded($run, $seed, "r".$run[0]) : $run;
        }
        return $order;
    }

    // Categories with their heading and blocks. The question cards come from $card(question index, displayed number).
    // $passages: false leaves out the content of the boxes (answer review with "review: 0")
    private function renderSections($quiz, $order, $passages, $card) {
        $output = "";
        $number = 0;
        foreach ($quiz["categories"] as $category) {
            $output .= "<div class=\"quiz-section\">\n";
            if ($quiz["hasCategories"] && count($category["questions"])) $output .= "<h2 class=\"quiz-category\">".$this->e($category["name"])."</h2>\n";
            $output .= $this->renderBlocks($quiz, $category["blocks"], $order, $card, $number, $passages);
            $output .= "</div>\n";
        }
        return $output;
    }

    // Text lines, runs of questions and boxes; a box and its questions form a group, the box floats over its questions
    private function renderBlocks($quiz, $blocks, $order, $card, &$number, $passages) {
        $output = "";
        foreach ($blocks as $block) {
            if ($block[0]=="line") {
                $output .= $this->renderLine($block[1])."\n";
            } elseif ($block[0]=="run") {
                foreach ($order[$block[1]] as $index) {
                    $number++;
                    foreach ($quiz["questions"][$index]["pre"] as $line) $output .= $this->renderLine($line)."\n";
                    $output .= $card($index, $number);
                }
            } else {
                $box = $quiz["boxes"][$block[1]];
                $first = $number+1;
                $inner = $this->renderBlocks($quiz, $box["blocks"], $order, $card, $number, $passages);
                // an empty box is not shown, its questions stay
                $output .= $passages && count($box["content"]) ? "<div class=\"quiz-group\">\n".$this->renderPassage($box["content"], $first, $number).$inner."</div>\n" : $inner;
            }
        }
        return $output;
    }

    // Floating box: its content and a badge with the displayed numbers of its questions
    private function renderPassage($content, $first, $last) {
        $output = "<div class=\"quiz-passage\">";
        if ($last>=$first) {
            $label = str_replace(array("@first", "@last"), array((string)$first, (string)$last), $this->text($first==$last ? "quizGroupOne" : "quizGroupMany"));
            $output .= "<div class=\"quiz-passage-label\"><span class=\"quiz-sr\">".$this->e($label)."</span><span aria-hidden=\"true\">".
                ($first==$last ? $first : $first."–".$last)."</span></div>";
        }
        $output .= "\n<div class=\"quiz-passage-body\">\n";
        foreach ($content as $line) $output .= $this->renderLine($line)."\n";
        return $output."</div></div>\n";
    }

    // Render quiz form; a quiz with essay questions also sends its signed facts and shows the name of the attempt.
    // The questions and boxes stay on this page only: search engines do not keep it, and the description of the page,
    // which Yellow otherwise takes from the beginning of the content, is the title.
    private function renderForm($quiz, $quizId, $attempt, $meta, $name) {
        $page = $this->yellow->page;
        $page->setHeader("X-Robots-Tag", "noindex, nofollow, noarchive");
        if (!$page->isExisting("description")) $page->set("description", $page->get("title"));
        $linkMode = $this->linkMode;
        $this->linkMode = "text"; // a link on the quiz page would lead away from the running quiz
        $settings = $quiz["settings"];
        $order = $this->getOrder($quiz, $attempt["seed"]);
        $count = count($quiz["questions"]);
        $minutes = $settings["time"];
        $remaining = $minutes>0 ? max(0, $attempt["start"]+$minutes*60-time()) : 0;
        $deadline = $this->getDeadline($settings, $attempt["start"]);
        $schedule = "";
        if ($settings["close"]>0) {
            $schedule .= " data-close-in=\"".($settings["close"]-time())."\" data-close-at=\"".date("H:i", $settings["close"])."\"";
            if ($minutes==0 && $deadline>0) $schedule .= " data-deadline-in=\"".max(0, $deadline-time())."\" data-end-at=\"".date("H:i", $deadline)."\"";
        }
        $output = "<div lang=\"en\" class=\"quiz-container quiz-protect quiz-needs-js\" id=\"quiz-{$quizId}\" data-quiz-id=\"{$quizId}\" data-attempt=\"".$this->e($attempt["token"])."\"";
        $output .= " data-time=\"{$minutes}\" data-remaining=\"{$remaining}\"".$schedule.$this->getLibraryAttributes($quiz);
        $output .= " data-i18n=\"".$this->e($this->toJson($this->getClientTexts(self::TEXTS_FORM)))."\">\n";
        $output .= "<div class=\"quiz-notice quiz-locked quiz-js-notice\">".$this->e($this->text("quizJsRequired"))."</div>\n";
        $output .= "<form class=\"quiz-form\" method=\"post\" action=\"".$this->e($this->getPagePath())."\">\n";
        $output .= "<input type=\"hidden\" name=\"quiz_id\" value=\"{$quizId}\" />\n";
        $output .= "<input type=\"hidden\" name=\"quiz_attempt\" value=\"".$this->e($attempt["token"])."\" />\n";
        $output .= "<input type=\"hidden\" name=\"quiz_client\" value=\"\" />\n";
        $output .= "<input type=\"hidden\" name=\"quiz_away\" value=\"0\" />\n";
        $output .= "<input type=\"hidden\" name=\"quiz_auto\" value=\"\" />\n";
        if ($meta) {
            $output .= $this->renderMetaFields($meta)."<input type=\"hidden\" name=\"quiz_device\" value=\"\" />\n";
            $output .= $this->renderSetupNotices($quiz);
            $output .= "<p class=\"quiz-essay-name\">".str_replace("@name", "<b>".$this->e($name)."</b>", $this->e($this->text("quizAnsweringAs")))."</p>\n";
        }
        foreach ($quiz["header"] as $line) $output .= $this->renderLine($line)."\n";
        $output .= $this->renderSections($quiz, $order, true, function($index, $number) use ($quiz, $quizId, $attempt) {
            $question = $quiz["questions"][$index];
            if ($question["essay"]!==null) return $this->renderEssayCard($quizId, $index, $number, $question);
            $labelId = "quiz-{$quizId}-q{$index}";
            $card = "<div class=\"quiz-q\" role=\"radiogroup\" aria-labelledby=\"{$labelId}\">\n";
            $card .= $this->renderQuestionText($question, $number, $labelId);
            $card .= "<div class=\"quiz-options\">\n";
            foreach ($this->getOptionOrder($question, $index, $attempt["seed"]) as $option) {
                $token = $this->getToken($quizId, $attempt["seed"], $index, $option);
                $card .= "<label class=\"quiz-option\"><input type=\"radio\" name=\"quest[{$index}]\" value=\"{$token}\" />";
                $card .= "<span class=\"quiz-option-text\">".$this->toHTML($question["options"][$option], false)."</span></label>\n";
            }
            return $card."</div>\n</div>\n";
        });
        $output .= "<div class=\"quiz-actions\"><button type=\"submit\" class=\"quiz-btn quiz-submit\">".$this->e($this->text("quizButton"))."</button></div>\n";
        $output .= "</form>\n";
        if ($minutes>0) {
            $output .= "<div class=\"quiz-progress\" role=\"timer\" aria-live=\"off\">";
            $output .= "<svg class=\"quiz-clock\" viewBox=\"0 0 24 24\" aria-hidden=\"true\"><circle cx=\"12\" cy=\"13\" r=\"8\"/><path d=\"M12 9v4l2.5 2.5M9 2.5h6\"/></svg>";
            $output .= "<span class=\"quiz-timer-main\"><span class=\"quiz-timer-label\">".$this->e($this->text("quizTimeLeft"))."</span>";
            $output .= "<span class=\"quiz-progresstext\">".floor($remaining/60).":".sprintf("%02d", $remaining%60)."</span></span>";
            $output .= "<span class=\"quiz-answered\"><b class=\"quiz-answered-count\">0/{$count}</b> ".$this->e($this->text("quizAnsweredLabel"))."</span>";
            $output .= "<span class=\"quiz-progressbar\"></span></div>\n";
        }
        $output .= "</div>\n";
        $this->linkMode = $linkMode;
        return $output;
    }

    // Grade the stored answers: every question counts 1, a wrong answer with penalty counts -1/(options-1).
    // Essay questions are not graded: the teacher scores them.
    private function gradeResult($quiz, $quizId, $data) {
        $seed = (string)$data["s"];
        $penaltyOn = $quiz["settings"]["penalty"]==1;
        $items = array();
        $right = $wrong = 0;
        foreach ($quiz["questions"] as $index=>$question) {
            if ($question["essay"]!==null) continue;
            $optionCount = count($question["options"]);
            $value = isset($data["a"][$index]) && is_string($data["a"][$index]) ? $data["a"][$index] : "";
            $chosen = -1;
            if (preg_match('/^[a-f0-9]{8}$/', $value)) {
                for ($option=0; $option<$optionCount; $option++) {
                    if (hash_equals(substr($this->getToken($quizId, $seed, $index, $option), 0, 8), $value)) { $chosen = $option; break; }
                }
            }
            $penalty = $penaltyOn ? 1/max(1, $optionCount-1) : 0;
            if ($chosen==0) { $units = 1; $status = "right"; $gain = 0; $right++; }
            elseif ($chosen>0) { $units = -$penalty; $status = "wrong"; $gain = 1+$penalty; $wrong++; }
            else { $units = 0; $status = "skipped"; $gain = 1; }
            $items[$index] = array("chosen"=>$chosen, "status"=>$status, "units"=>$units, "gain"=>$gain, "penalty"=>$penalty, "category"=>$question["category"]);
        }
        return array("items"=>$items, "right"=>$right, "wrong"=>$wrong);
    }

    // Score 0-100 of a set of items (whole quiz or one category), one decimal
    private function getPercent($units, $count) {
        if ($count<=0) return 0.0;
        return round(max(0, min(100, $units*100/$count)), 1);
    }

    // Score, mastery per category and certificate requirements of a signed result; null when the result is not valid
    private function evaluateResult($quiz, $quizId, $data) {
        $settings = $quiz["settings"];
        $start = intval($data["t"]);
        $submitted = intval($data["u"]);
        if ($submitted<$start || $submitted>$start+$this->getLifetime($settings["time"])) return null;
        $seed = (string)$data["s"];
        $grade = $this->gradeResult($quiz, $quizId, $data);
        $items = $grade["items"];
        $count = count($items);
        $totalUnits = 0;
        $penaltyUnits = 0;
        $categoryStats = array();
        foreach ($items as $item) {
            $totalUnits += $item["units"];
            if ($item["status"]=="wrong") $penaltyUnits += $item["penalty"];
            $c = $item["category"];
            if (!isset($categoryStats[$c])) $categoryStats[$c] = array("units"=>0, "count"=>0, "right"=>0);
            $categoryStats[$c]["units"] += $item["units"];
            $categoryStats[$c]["count"]++;
            if ($item["status"]=="right") $categoryStats[$c]["right"]++;
        }
        $score = $this->getPercent($totalUnits, $count);
        $parts = array();
        foreach ($quiz["categories"] as $index=>$category) {
            if (!isset($categoryStats[$index])) continue;
            $parts[] = array($category["name"], $this->getPercent($categoryStats[$index]["units"], $categoryStats[$index]["count"]), $index);
        }
        $requirements = $this->getRequirements($quiz);
        $below = array(); // required categories that have not reached their pass mark
        foreach ($parts as $part) {
            if (isset($requirements[$part[2]]) && $part[1]<$requirements[$part[2]]) $below[] = $part;
        }
        $meets = count($requirements)>0 && !count($below);
        $deadline = $this->getDeadline($settings, $start);
        $isLate = $deadline>0 && $submitted>$deadline+self::GRACE;
        $nonce = $this->getNonce($quizId, $seed, $start);
        $certParts = array();
        if ($quiz["hasCategories"]) foreach ($parts as $part) $certParts[] = array($part[0], $part[1]);
        return array("seed"=>$seed, "start"=>$start, "submitted"=>$submitted, "grade"=>$grade, "items"=>$items, "count"=>$count,
            "penaltyUnits"=>$penaltyUnits, "categoryStats"=>$categoryStats, "score"=>$score, "parts"=>$parts, "certParts"=>$certParts,
            "requirements"=>$requirements, "below"=>$below, "meets"=>$meets, "isLate"=>$isLate, "nonce"=>$nonce);
    }

    // Render result: score, mastery per category, certificate state and answer review.
    // A quiz with essay questions issued its certificate when the answers were sent, with the name given at the start;
    // its essay answers are read from the record file.
    private function renderResult($quiz, $quizId, $recordKey, $data, $title, $resultToken) {
        $settings = $quiz["settings"];
        $result = $this->evaluateResult($quiz, $quizId, $data);
        if ($result===null) return $this->renderInvalid($quizId);
        $seed = $result["seed"];
        $grade = $result["grade"];
        $items = $result["items"];
        $count = $result["count"];
        $score = $result["score"];
        $requirements = $result["requirements"];
        $below = $result["below"];
        $isLate = $result["isLate"];
        $nonce = $result["nonce"];
        $certState = "none";
        $issuedTo = "";
        $payload = "";
        $certificate = null;
        $kept = false;
        $essayAnswers = null; // null: the essay answers of this attempt are not stored
        if ($quiz["hasEssays"]) {
            $record = $this->findEssayRecord($recordKey, $nonce);
            if ($record && $record["essay_attempt"]===$nonce) {
                $essayAnswers = array();
                $essays = $this->decodeEssays($record["essays"]);
                foreach ($essays["items"] as $item) $essayAnswers[$item["index"]] = $item;
            }
            if ($record && $record["certificate_no"]!="") {
                $certState = "download";
                $certificate = $this->getCertificateData($record);
                $kept = $record["attempt"]!==$nonce;
            }
        } elseif (!$isLate && $result["meets"]) {
            $record = $this->findRecord($recordKey, "attempt", $nonce);
            if ($record) {
                $certState = "used";
                $issuedTo = $record["name"];
            } else {
                $certState = time()>$result["submitted"]+self::CERT_WINDOW ? "expired" : "open";
            }
            if ($certState=="open") {
                $payload = $this->toJson(array("file"=>$recordKey, "nonce"=>$nonce, "title"=>$title, "score"=>$score, "parts"=>$result["certParts"],
                    "sub"=>$result["submitted"], "min"=>round(($result["submitted"]-$result["start"])/60, 1)));
            }
        }
        $resultData = array("id"=>$quizId, "title"=>$title, "score"=>$score, "cert"=>$certState,
            "payload"=>$payload, "sig"=>$payload!="" ? $this->sign("cert|".$payload) : "");
        if ($certificate) $resultData["certificate"] = $certificate;
        $output = "<div lang=\"en\" class=\"quiz-container quiz-is-result quiz-protect\" id=\"quiz-{$quizId}\" data-quiz-id=\"{$quizId}\"";
        if ($resultToken!="") $output .= " data-result-token=\"".$this->e($resultToken)."\"";
        $output .= $this->getLibraryAttributes($quiz);
        $output .= " data-result=\"".$this->e($this->toJson($resultData))."\" data-i18n=\"".$this->e($this->toJson($this->getClientTexts(array_merge(self::TEXTS_RESULT, self::TEXTS_CERTIFICATE))))."\">\n";
        // summary card; a quiz with only essay questions has no score
        $output .= "<div class=\"quiz-summary\">";
        if ($count>0) {
            $output .= "<div class=\"quiz-stats\" aria-hidden=\"true\"><div class=\"quiz-score\"><span class=\"quiz-score-value\">".$this->formatNumber($score)."</span>";
            $output .= "<span class=\"quiz-score-max\">/ 100</span></div></div>";
        }
        $output .= "<div class=\"quiz-summary-text\">";
        if ($count>0) {
            $output .= "<p>".str_replace(array("@right_answers", "@curr_question"), array((string)$grade["right"], (string)$count), $this->text("quizResult"))."</p>";
            $scoreLine = str_replace(array("@score", "@max_score"), array($this->formatNumber($score), "100"), $this->text("quizScore"));
            if ($settings["penalty"]==1) $scoreLine = rtrim($scoreLine, ". ").". ".$this->e($this->getPenaltyText($grade["wrong"], $result["penaltyUnits"], $count));
            $output .= "<p>".$scoreLine."</p>";
        }
        if ($quiz["hasCategories"] && count($result["parts"])) {
            $output .= "<div class=\"quiz-parts\">";
            foreach ($result["parts"] as $part) {
                $mark = !isset($requirements[$part[2]]) ? "" : ($part[1]>=$requirements[$part[2]] ? " <span class=\"quiz-part-ok\">✓</span>" : " <span class=\"quiz-part-low\">✕</span>");
                $output .= "<span class=\"quiz-part\">".$this->e($part[0])." <span class=\"quiz-part-value\">".$this->formatNumber($part[1])."%</span>{$mark}</span>";
            }
            $output .= "</div>";
            if (count($requirements)) $output .= "<p class=\"quiz-parts-note\">".$this->e($this->text("quizCategoryRequired"))."</p>";
        }
        if ($quiz["hasEssays"]) {
            $output .= "<p class=\"quiz-info".($essayAnswers===null ? " quiz-info-strong" : "")."\">".
                $this->e($this->text($essayAnswers===null ? "quizEssayNotStored" : "quizEssaySaved"))."</p>";
        }
        if ($data["x"]==2) $output .= "<p class=\"quiz-info quiz-info-strong\">".$this->e($this->text("quizAwaySubmitted"))."</p>";
        elseif ($data["w"]>0) $output .= "<p class=\"quiz-info\">".$this->e(str_replace("@count", $data["w"], $this->text("quizAwayCount")))."</p>";
        $output .= "<div class=\"quiz-summary-actions\">";
        if ($certState=="open") {
            $output .= "<button type=\"button\" class=\"quiz-btn quiz-cert-open\">".$this->e($this->text("quizCertButton"))."</button>";
        } elseif ($certState=="used") {
            $output .= "<button type=\"button\" class=\"quiz-btn quiz-cert-open is-done\" disabled=\"disabled\">".
                $this->e(str_replace("@name", $issuedTo, $this->text("quizCertIssued")))."</button>";
        } elseif ($certState=="download") {
            $output .= "<button type=\"button\" class=\"quiz-btn quiz-cert-download\">".$this->e($this->text("quizCertDownload"))."</button>";
        }
        $output .= "<a class=\"quiz-btn quiz-btn-quiet\" href=\"".$this->e($this->getRetakeUrl($quizId))."\">".$this->e($this->text("quizRetake"))."</a>";
        $output .= "</div>";
        if ($certState=="download") {
            $output .= "<p class=\"quiz-info\">".$this->e(str_replace(array("@name", "@score"), array($certificate["name"], $this->formatNumber($certificate["score"])),
                $this->text($kept ? "quizCertBest" : "quizCertFor")))."</p>";
        }
        if (count($below)) {
            $output .= "<div class=\"quiz-cert-hint\"><span class=\"quiz-cert-hint-head\">".$this->e($this->text("quizCertNeedHead"))."</span>";
            foreach ($this->getCertificateHint($quiz, $items, $result["categoryStats"], $requirements, $below) as $line) {
                $output .= "<span class=\"quiz-cert-hint-line\">".$this->e($line)."</span>";
            }
            $output .= "</div>";
        }
        if ($certState=="expired") $output .= "<p class=\"quiz-cert-hint\">".$this->e($this->text("quizCertExpired"))."</p>";
        $output .= "</div></div>\n";
        $output .= "<p class=\"quiz-forget-line\"><button type=\"button\" class=\"quiz-forget\">".$this->e($this->text("quizForget"))."</button></p>\n";
        if ($isLate) $output .= "<div class=\"quiz-notice quiz-locked\">".$this->e($this->text("quizLate"))."</div>\n";
        // answer review (not shown with "review: card"); the content of the boxes only with "review: 1"
        if ($settings["review"]!=2) {
            $showKey = $settings["review"]==1;
            $output .= "<div class=\"quiz-notice\">".($showKey ? $this->text("quizCorrected") : $this->e($this->text("quizCorrectedMarks")))."</div>\n";
            foreach ($quiz["header"] as $line) $output .= $this->renderLine($line)."\n";
            $output .= $this->renderSections($quiz, $this->getOrder($quiz, $seed), $showKey, function($index, $number) use ($quiz, $items, $seed, $showKey, $essayAnswers) {
                $question = $quiz["questions"][$index];
                if ($question["essay"]!==null) return $this->renderEssayReview($question, $number, $essayAnswers!==null && isset($essayAnswers[$index]) ? $essayAnswers[$index] : null);
                $item = $items[$index];
                $card = "<div class=\"quiz-q quiz-corrected q-".$item["status"]."\">\n";
                $card .= $this->renderQuestionText($question, $number, "");
                $card .= $this->renderNotes($question["notes"], $item["status"]=="right");
                $card .= "<div class=\"quiz-options\">\n";
                foreach ($this->getOptionOrder($question, $index, $seed) as $option) {
                    $html = $this->toHTML($question["options"][$option], false);
                    if ($option==0 && ($showKey || $item["chosen"]==0)) {
                        $card .= "<div class=\"quiz-option is-correct\"><span class=\"quiz-option-text\"><b>{$html}</b></span></div>\n";
                    } elseif ($option==$item["chosen"]) {
                        $card .= "<div class=\"quiz-option is-wrong\"><span class=\"quiz-option-text\"><del class=\"quiz-error\">{$html}</del></span></div>\n";
                    } else {
                        $card .= "<div class=\"quiz-option\"><span class=\"quiz-option-text\">{$html}</span></div>\n";
                    }
                }
                $card .= "</div>\n";
                if ($item["status"]=="skipped") $card .= "<p class=\"quiz-q-note\">".$this->e($this->text("quizNotAnswered"))."</p>\n";
                return $card."</div>\n";
            });
        }
        $output .= "</div>\n";
        return $output;
    }

    // Notes of a question above its options in the answer review ("review: 0" and "review: 1").
    // Note 0 only for a wrong or missing answer, in red at the top; notes 1, 2, ... always. Nothing when no note is left.
    private function renderNotes($notes, $answeredRight) {
        $output = "";
        foreach ($notes as $note) {
            if ($note[0]==0 && $answeredRight) continue;
            $output .= "<p class=\"quiz-explain-text".($note[0]==0 ? " quiz-explain-wrong" : "")."\">".$this->toHTML($note[1], false, false)."</p>";
        }
        if ($output=="") return "";
        return "<div class=\"quiz-explain\"><span class=\"quiz-explain-label\">".$this->e($this->text("quizExplanation"))."</span>".$output."</div>\n";
    }

    // Essay question in the answer review: the stored answer of this attempt with its number of words
    private function renderEssayReview($question, $number, $item) {
        $card = "<div class=\"quiz-q quiz-corrected quiz-essay q-essay\">\n";
        $card .= $this->renderQuestionText($question, $number, "");
        if ($item===null) {
            $card .= "<p class=\"quiz-q-note\">".$this->e($this->text("quizEssayNotShown"))."</p>\n";
        } elseif ($item["answer"]==="") {
            $card .= "<p class=\"quiz-q-note\">".$this->e($this->text("quizNotAnswered"))."</p>\n";
        } else {
            $card .= "<div class=\"quiz-essay-answer\">".nl2br($this->e($item["answer"]), false)."</div>\n";
            $card .= "<p class=\"quiz-q-note\">".$this->e(str_replace("@count", (string)$item["words"], $this->text($item["words"]==1 ? "quizEssayReviewOne" : "quizEssayReviewMany")))."</p>\n";
        }
        return $card."</div>\n";
    }

    // Penalty after the score: the points taken off the score (one decimal, like the score) and the number of wrong answers
    private function getPenaltyText($wrong, $penaltyUnits, $count) {
        if ($wrong==0) return $this->text("quizPenaltyNone");
        $points = round($penaltyUnits*100/$count, 1);
        $points = $this->formatNumber($points).($points==1 ? " point" : " points");
        return str_replace(array("@points", "@count"), array($points, (string)$wrong), $this->text($wrong==1 ? "quizPenaltyOne" : "quizPenaltyMany"));
    }

    // What is missing for the certificate: one line per required category below its pass mark.
    // The count is the fewest extra right answers: the answers that gain the most come first
    // (with penalty, a wrong answer made right gains more than an empty one).
    private function getCertificateHint($quiz, $items, $categoryStats, $requirements, $below) {
        $lines = array();
        foreach ($below as $part) {
            $c = $part[2];
            $gains = array();
            foreach ($items as $item) if ($item["category"]==$c && $item["gain"]>0) $gains[] = $item["gain"];
            rsort($gains);
            $units = $categoryStats[$c]["units"];
            $needed = 0;
            while ($this->getPercent($units, $categoryStats[$c]["count"])<$requirements[$c] && count($gains)) {
                $units += array_shift($gains);
                $needed++;
            }
            if ($quiz["settings"]["penalty"]==1) {
                $lines[] = str_replace(array("@name", "@percent", "@pass", "@count"),
                    array($part[0], $this->formatNumber($part[1]), $this->formatNumber($requirements[$c]), (string)$needed),
                    $this->text($needed==1 ? "quizCertNeedPercentOne" : "quizCertNeedPercent"));
            } else {
                $lines[] = str_replace(array("@name", "@right", "@total", "@needed"),
                    array($part[0], (string)$categoryStats[$c]["right"], (string)$categoryStats[$c]["count"],
                    (string)$this->getNeededRight($requirements[$c], $categoryStats[$c]["count"])), $this->text("quizCertNeedRight"));
            }
        }
        return $lines;
    }

    // Reprint form: on its own page ([quizcertificate]) with a title, or inside the start popup without one
    private function renderReprint($inline) {
        static $counter = 0;
        $counter++;
        $days = $this->getKeepDays();
        $keep = $days>0 ? str_replace("@days", $days, $this->text("quizReprintKeep")) : $this->text("quizReprintKeepForever");
        $texts = $this->getClientTexts(array_merge(self::TEXTS_REPRINT, self::TEXTS_CERTIFICATE));
        $id = "quiz-reprint-number-".$counter;
        $form = "<div class=\"quiz-reprint\" data-i18n=\"".$this->e($this->toJson($texts))."\">\n<div class=\"quiz-reprint-card\">\n";
        if (!$inline) $form .= "<p class=\"quiz-reprint-title\">".$this->e($this->text("quizReprintTitle"))."</p>\n";
        $form .= "<p class=\"quiz-reprint-text\">".$this->e($this->text("quizReprintPrompt"))." ".$this->e($keep)."</p>\n";
        $form .= "<form class=\"quiz-reprint-form\" method=\"post\" novalidate>\n";
        $form .= "<label class=\"quiz-reprint-label\" for=\"{$id}\">".$this->e($this->text("quizReprintLabel"))."</label>\n";
        $form .= "<div class=\"quiz-reprint-row\"><input type=\"text\" id=\"{$id}\" name=\"number\" maxlength=\"20\" autocomplete=\"off\" spellcheck=\"false\" placeholder=\"".$this->e($this->text("quizReprintPlaceholder"))."\" />";
        $form .= "<button type=\"submit\" class=\"quiz-btn\">".$this->e($this->text("quizReprintButton"))."</button></div>\n";
        $form .= "</form>\n<p class=\"quiz-reprint-message\" role=\"status\" hidden></p>\n</div>\n</div>\n";
        return $form;
    }

    // [quizleaderboard file.txt] or [quizleaderboard file.txt 10]: certificates of one quiz, best score per full name.
    // Only rank, full name, score and date are shown: no certificate numbers, device codes or other codes.
    private function renderLeaderboard($text) {
        list($fileName, $limit) = $this->yellow->toolbox->getTextArguments($text);
        $limit = ctype_digit($limit) ? intval($limit) : 0;
        if (!$this->isQuizFileName($fileName)) return null;
        $prefix = substr($this->getRecordKey($fileName, "000000000000"), 0, -6);
        $directory = $this->getDataDirectory(false);
        $best = array();
        if ($directory!==false) {
            $stem = $directory.$prefix."[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]";
            foreach (array_merge((array)glob($stem.".csv"), (array)glob($stem."-essays.csv")) as $file) {
                foreach ($this->readRecords($file)["records"] as $record) {
                    if ($record["certificate_no"]=="") continue; // essay answers without a certificate
                    $key = $this->getNameKey($record["name"]);
                    $score = floatval($record["score"]);
                    if (!isset($best[$key]) || $score>$best[$key][1] || ($score==$best[$key][1] && $record["issued"]<$best[$key][2])) {
                        $best[$key] = array($record["name"], $score, $record["issued"]);
                    }
                }
            }
        }
        usort($best, function($a, $b) { return $a[1]==$b[1] ? strcmp($a[2], $b[2]) : ($a[1]<$b[1] ? 1 : -1); });
        if ($limit>0) $best = array_slice($best, 0, $limit);
        $output = "<div lang=\"en\" class=\"quiz-container quiz-board\" data-i18n=\"".$this->e($this->toJson($this->getClientTexts(array())))."\">\n";
        if (!count($best)) return $output."<p class=\"quiz-board-empty\">".$this->e($this->text("quizBoardEmpty"))."</p>\n</div>\n";
        $output .= "<div class=\"quiz-board-row quiz-board-head\"><span>".$this->e($this->text("quizBoardRank"))."</span><span>".$this->e($this->text("quizBoardName")).
            "</span><span>".$this->e($this->text("quizBoardScore"))."</span><span>".$this->e($this->text("quizBoardDate"))."</span></div>\n";
        foreach ($best as $index=>$row) {
            $output .= "<div class=\"quiz-board-row\"><span>".($index+1)."</span><span>".$this->e($row[0])."</span><span>".$this->formatNumber($row[1]).
                "</span><span>".$this->e(substr($row[2], 0, 10))."</span></div>\n";
        }
        return $output."</div>\n";
    }

    // Render notice for a forged, broken or expired attempt or result, or an attempt submitted before: nothing is graded
    private function renderInvalid($quizId, $text = "") {
        $output = "<div lang=\"en\" class=\"quiz-container\" id=\"quiz-{$quizId}\">\n";
        $output .= "<div class=\"quiz-notice quiz-locked\">".$this->e($text!="" ? $text : $this->text("quizInvalid"))."</div>\n";
        $output .= "<a class=\"quiz-btn quiz-btn-quiet\" href=\"".$this->e($this->getRetakeUrl($quizId))."\">".$this->e($this->text("quizRetake"))."</a>\n";
        $output .= "</div>\n";
        return $output;
    }

    private function renderNotice($quizId, $text) {
        $id = $quizId!="" ? " id=\"quiz-{$quizId}\"" : "";
        return "<div lang=\"en\" class=\"quiz-container\"{$id}><div class=\"quiz-notice quiz-locked\">".$this->e($text)."</div></div>\n";
    }

    // Path of the current page without scheme and host, so links work whatever host name the server reports
    private function getPagePath() {
        return $this->yellow->page->getLocation(true);
    }

    // Link that always loads the page again (a link differing only by #anchor would just scroll)
    private function getRetakeUrl($quizId) {
        $url = $this->getPagePath();
        $url .= (strpos($url, "?")===false ? "?" : "&")."quiz_retake=".$quizId;
        return $url."#quiz-{$quizId}";
    }

    // Render question text with its displayed number
    private function renderQuestionText($question, $number, $labelId) {
        $id = $labelId!="" ? " id=\"{$labelId}\"" : "";
        return "<div class=\"quiz-q-text\"{$id}><span class=\"quiz-num\">{$number}</span><div class=\"quiz-q-body\">".$this->toHTML($question["text"], false)."</div></div>\n";
    }

    // Return display order of options, index 0 is always the correct option; options are always shuffled
    private function getOptionOrder($question, $index, $seed) {
        if ($question["tf"]) return $question["trueFirst"] ? array(0, 1) : array(1, 0);
        return $this->shuffleSeeded(range(0, count($question["options"])-1), $seed, "a".$index);
    }

    // Shuffle array with a seed, same seed gives same order. The seed is part of the attempt and known to the browser,
    // so every step is keyed with the secret of the server: without it the order of the options, and with it the
    // place of option 0 (the right answer), cannot be computed.
    private function shuffleSeeded($items, $seed, $salt) {
        $items = array_values($items);
        for ($i=count($items)-1; $i>0; $i--) {
            $j = hexdec(substr($this->sign("order|".$seed."|".$salt."|".$i), 0, 7)) % ($i+1);
            $swap = $items[$i];
            $items[$i] = $items[$j];
            $items[$j] = $swap;
        }
        return $items;
    }

    // Return token for an answer, bound to quiz, attempt, question and option
    private function getToken($quizId, $seed, $question, $option) {
        return substr($this->sign("answer|".$quizId."|".$seed."|".$question."|".$option), 0, 16);
    }

    // Numbers are shown with at most one decimal; pass marks are compared with these shown numbers
    private function formatNumber($number) {
        $text = (string)round((float)$number, 1);
        return $text=="-0" ? "0" : $text;
    }

    // Issue a certificate once per attempt, the same name may download it again
    private function issueCertificate($input) {
        $payload = isset($input["cert_payload"]) && is_string($input["cert_payload"]) ? $input["cert_payload"] : "";
        $signature = isset($input["cert_sig"]) && is_string($input["cert_sig"]) ? $input["cert_sig"] : "";
        $name = $this->cleanName(isset($input["cert_name"]) && is_string($input["cert_name"]) ? $input["cert_name"] : "");
        $data = json_decode($payload, true);
        if ($this->getSecret()=="" || $payload=="" || !hash_equals($this->sign("cert|".$payload), $signature) || !is_array($data) ||
            !isset($data["file"], $data["nonce"], $data["title"], $data["score"], $data["sub"], $data["parts"], $data["min"]) || !is_array($data["parts"]) || !is_numeric($data["min"]) ||
            !preg_match('/^[a-z0-9\-]{1,80}$/', (string)$data["file"])) {
            $response = array("ok"=>false, "error"=>"invalid");
        } elseif (time()>intval($data["sub"])+self::CERT_WINDOW) {
            $response = array("ok"=>false, "error"=>"expired");
        } elseif ($name=="") {
            $response = array("ok"=>false, "error"=>"name");
        } else {
            $device = isset($input["cert_device"]) && is_string($input["cert_device"]) && preg_match('/^[a-f0-9]{16,32}$/', $input["cert_device"]) ? $input["cert_device"] : "none";
            $response = $this->registerCertificate($data, $name, $device);
        }
        return $response;
    }

    // Find certificate by number for reprinting, expired or deleted records are not found
    private function reprintCertificate($input) {
        $number = isset($input["number"]) && is_string($input["number"]) ? strtoupper(preg_replace('/[\s\-]+/', "", $input["number"])) : "";
        if (!preg_match('/^[0-9A-F]{10}$/', $number) || $this->getSecret()=="") return array("ok"=>false, "error"=>"invalid");
        $directory = $this->getDataDirectory(false);
        $files = $directory!==false ? (array)glob($directory."*.csv") : array();
        $record = $this->searchFiles($files, "certificate_no", $number);
        return $record ? $this->getCertificateData($record) : array("ok"=>false, "error"=>"not_found");
    }

    // Clean name: no control or invisible formatting characters, single spaces, 1-60 characters
    private function cleanName($name) {
        if (!preg_match("//u", $name)) return "";
        $name = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', "", $name);
        $name = trim(preg_replace('/\s+/u', " ", $name));
        return preg_match('/^.{1,60}$/u', $name) ? $name : "";
    }

    // Store certificate in the record file of its quiz, one writer at a time.
    // One person (full name + device stamp) keeps one certificate per quiz: the one with the highest score.
    private function registerCertificate($data, $name, $device) {
        $directory = $this->getDataDirectory(true);
        if ($directory===false) return array("ok"=>false, "error"=>"storage");
        $lock = $this->lockData($directory, true);
        if (!$lock) return array("ok"=>false, "error"=>"storage");
        $file = $directory.$data["file"].".csv";
        $identity = $this->getIdentity($name, $device);
        $existing = $this->findRecord($data["file"], "attempt", $data["nonce"]);
        $best = $existing ? null : $this->findRecord($data["file"], "identity", $identity);
        if ($existing) {
            $response = $existing["name"]===$name ? $this->getCertificateData($existing) :
                array("ok"=>false, "error"=>"used", "issuedTo"=>$existing["name"]);
        } elseif ($best && floatval($best["score"])>=floatval($data["score"])) {
            $response = $this->getCertificateData($best); // a lower or equal score keeps the better certificate
            $response["kept"] = true;
        } elseif (!$this->isSubmitted($data["nonce"]) || (is_file($file) && filesize($file)>=self::FILE_MAX)) {
            $response = array("ok"=>false, "error"=>"storage"); // a submission that could not be kept gets no certificate
        } else {
            $record = array("issued"=>date("Y-m-d H:i:s"), "certificate_no"=>$this->getUniqueNumber($directory, $data["nonce"], $name),
                "name"=>$name, "device"=>$device==="none" ? "" : substr($this->sign("device|".$device), 0, 6), "minutes"=>$this->formatNumber($data["min"]),
                "score"=>$this->formatNumber($data["score"]),
                "quiz_title"=>(string)$data["title"], "attempt"=>(string)$data["nonce"], "parts"=>$this->encodeParts($data["parts"]),
                "identity"=>$identity);
            if (is_file($file) && filesize($file)>0 && $this->getHeaderType($file)!="base") {
                $ok = false; // a record file with other columns is never written to
            } elseif ($best) {
                $ok = $this->replaceRecord($file, $best["certificate_no"], $record);
            } else {
                $ok = $this->appendRecord($file, $record);
            }
            $response = $ok ? $this->getCertificateData($record) : array("ok"=>false, "error"=>"storage");
        }
        $this->unlockData($lock);
        return $response;
    }

    // Same person = same full name (case and extra spaces ignored) on the same device
    private function getIdentity($name, $device) {
        return substr($this->sign("identity|".$this->getNameKey($name)."|".$device), 0, 16);
    }

    // Full name without differences of case and spaces, for comparing names
    private function getNameKey($name) {
        $name = function_exists("mb_strtolower") ? mb_strtolower($name, "UTF-8") : strtolower($name);
        return preg_replace('/\s+/u', " ", trim($name));
    }

    // ---------------------------------------------------------------- data page

    // [quizdata]: the record files, only for logged-in editors listed in QuizDataUsers. Read only.
    // Without "?quiz_data=file.csv" the files are listed; with it, the rows of that file are shown by quiz.js.
    private function renderDataPage() {
        $this->yellow->page->setHeader("Cache-Control", "no-store, max-age=0");
        $output = "<div lang=\"en\" class=\"quiz-container quiz-data\">\n";
        $output .= "<div class=\"quiz-data-title\" role=\"heading\" aria-level=\"2\">".$this->e($this->text("quizDataTitle"))."</div>\n";
        $email = $this->getEditorEmail();
        if ($email=="") {
            $output .= "<p class=\"quiz-data-message\">".$this->e($this->text("quizDataLogin"))."</p>\n";
            $editLocation = rtrim((string)$this->yellow->system->get("editLocation"), "/");
            if ($editLocation!="") {
                $link = $this->yellow->system->get("coreServerBase").$editLocation.$this->yellow->page->location;
                $output .= "<p class=\"quiz-data-message\"><a class=\"quiz-btn\" href=\"".$this->e($link)."\">".$this->e($this->text("quizDataOpenEditor"))."</a></p>\n";
            }
            return $output."</div>\n";
        }
        $users = preg_split('/[\s,;]+/', strtolower(trim((string)$this->yellow->system->get("quizDataUsers"))), -1, PREG_SPLIT_NO_EMPTY);
        if (!in_array(strtolower($email), $users, true)) {
            $text = count($users) ? str_replace("@email", $email, $this->text("quizDataNotAllowed")) : $this->text("quizDataNoUsers");
            return $output."<p class=\"quiz-data-message\">".$this->e($text)."</p>\n</div>\n";
        }
        if ($this->getDataPath()===false) return $output."<p class=\"quiz-data-message\">".$this->e($this->text("quizDataUnsafe"))."</p>\n</div>\n";
        $this->sweepRecords();
        $directory = $this->getDataDirectory(false);
        $files = $directory!==false ? (array)glob($directory."*.csv") : array();
        $days = $this->getKeepDays();
        $output .= "<p class=\"quiz-data-keep\">".$this->e($days>0 ? str_replace("@days", (string)$days, $this->text("quizDataKeep")) : $this->text("quizDataKeepForever"))."</p>\n";
        if (!count($files)) return $output."<p class=\"quiz-data-message\">".$this->e($this->text("quizDataNoFolder"))."</p>\n</div>\n";
        $data = array("page"=>$this->getPagePath(), "updated"=>date("H:i:s"), "files"=>array());
        $chosen = $this->getQuery("quiz_data");
        foreach ($files as $file) {
            $read = $this->readDataFile($file);
            $info = array("file"=>basename($file), "size"=>filesize($file), "rows"=>count($read["rows"]), "kind"=>$this->getHeaderType($file),
                "title"=>"", "last"=>"");
            $titleColumn = array_search("quiz_title", $read["header"], true);
            foreach ($read["rows"] as $row) {
                if ($titleColumn!==false && isset($row[$titleColumn]) && $row[$titleColumn]!="") $info["title"] = $this->displayName($row[$titleColumn]);
                $info["last"] = max($info["last"], $this->getRowTime($read["header"], $row));
            }
            $data["files"][] = $info;
            if ($chosen===basename($file)) $data["file"] = array("name"=>basename($file), "header"=>$read["header"], "rows"=>$read["rows"]);
        }
        $output .= "<div class=\"quiz-data-app\" data-data=\"".$this->e($this->toJson($data))."\" data-i18n=\"".$this->e($this->toJson($this->getClientTexts(self::TEXTS_DATA)))."\">";
        $output .= "<p class=\"quiz-data-message\">".$this->e($this->text("quizDataNoScript"))."</p></div>\n";
        return $output."</div>\n";
    }

    // All rows of a record file as stored, with its header; names and titles without the spreadsheet protection mark
    private function readDataFile($file) {
        $header = array();
        $rows = array();
        $handle = @fopen($file, "r");
        if ($handle) {
            $first = fgetcsv($handle, 0, ",", "\"", "\\");
            if (is_array($first)) {
                $header = array_map(function($value) { return trim((string)$value); }, $first);
                while (($row = fgetcsv($handle, 0, ",", "\"", "\\"))!==false) {
                    if ($row===array(null)) continue; // empty line
                    foreach ($row as $index=>$value) {
                        $value = (string)$value;
                        if (isset($header[$index]) && ($header[$index]=="name" || $header[$index]=="quiz_title")) $value = $this->displayName($value);
                        $row[$index] = $this->cleanUtf8($value);
                    }
                    $rows[] = $row;
                }
            }
            fclose($handle);
        }
        return array("header"=>$header, "rows"=>$rows);
    }

    // Time of the last change of a row: the certificate or the newest essay answers
    private function getRowTime($header, $row) {
        $issued = array_search("issued", $header, true);
        $time = $issued!==false && isset($row[$issued]) ? (string)$row[$issued] : "";
        $column = array_search("essays", $header, true);
        $essays = $column!==false && isset($row[$column]) ? $this->decodeEssays($row[$column]) : null;
        return $essays && $essays["time"]>$time ? $essays["time"] : $time;
    }

    // ---------------------------------------------------------------- essay questions

    // Signed facts of a quiz with essay questions (file, record file, title): sent with the name at the start
    // and with the answers, so the server can store the essay answers before the page is built
    private function getQuizMeta($quizId, $fileName, $recordKey, $title) {
        $json = $this->toJson(array("file"=>$fileName, "key"=>$recordKey, "title"=>$title));
        return array("json"=>$json, "sig"=>substr($this->sign("meta|".$quizId."|".$json), 0, 32));
    }

    private function readQuizMeta($quizId) {
        $json = $this->getPost("quiz_meta");
        if ($json=="" || !hash_equals(substr($this->sign("meta|".$quizId."|".$json), 0, 32), $this->getPost("quiz_meta_sig"))) return null;
        $meta = json_decode($json, true);
        if (!is_array($meta) || !isset($meta["file"], $meta["key"], $meta["title"])) return null;
        if (!is_string($meta["file"]) || !is_string($meta["key"]) || !is_string($meta["title"]) || !preg_match('/^[a-z0-9\-]{1,80}$/', $meta["key"])) return null;
        return $meta;
    }

    private function renderMetaFields($meta) {
        return "<input type=\"hidden\" name=\"quiz_meta\" value=\"".$this->e($meta["json"])."\" />\n".
            "<input type=\"hidden\" name=\"quiz_meta_sig\" value=\"".$this->e($meta["sig"])."\" />\n";
    }

    // The name given at the start is kept with the attempt in a signed cookie; it cannot be changed during the attempt
    private function getNameStamp($quizId, $attempt, $name) {
        return substr($this->sign("name|".$quizId."|".$attempt["seed"]."|".$name), 0, 24);
    }

    private function sendNameCookie($quizId, $attempt, $name, $minutes) {
        $stamp = $this->getNameStamp($quizId, $attempt, $name);
        if (!headers_sent()) {
            @header("Set-Cookie: yquizn_".$quizId."=".rawurlencode($name)."~".$stamp."; Path=/; Max-Age=".$this->getLifetime($minutes).$this->getCookieFlags(), false);
        }
        $_COOKIE["yquizn_".$quizId] = $name."~".$stamp; // known at once, when the form is built in this request
    }

    private function getAttemptName($quizId, $attempt) {
        $value = isset($_COOKIE["yquizn_".$quizId]) && is_string($_COOKIE["yquizn_".$quizId]) ? $_COOKIE["yquizn_".$quizId] : "";
        if (!preg_match('/^(.+)~([a-f0-9]{24})$/s', $value, $matches)) return "";
        $name = $this->cleanName($matches[1]);
        return $name!="" && hash_equals($this->getNameStamp($quizId, $attempt, $name), $matches[2]) ? $name : "";
    }

    // Start (or continue) an attempt of a quiz with essay questions under the name given at the start
    private function startEssayAttempt($quizId, $quiz, $name) {
        $minutes = $quiz["settings"]["time"];
        $cookie = "yquiz_".$quizId;
        $attempt = isset($_COOKIE[$cookie]) && is_string($_COOKIE[$cookie]) ? $this->parseAttempt($quizId, $_COOKIE[$cookie], $minutes, true) : null;
        if (!$attempt) {
            $attempt = $this->newAttempt($quizId);
            $this->sendAttemptCookie($quizId, $attempt, $minutes);
            $_COOKIE[$cookie] = $attempt["token"];
        }
        if ($this->getAttemptName($quizId, $attempt)=="") $this->sendNameCookie($quizId, $attempt, $name, $minutes);
        return $attempt;
    }

    // Address to continue after "Start quiz" with a name, empty when the page must handle the request
    private function getStartRedirect() {
        $quizId = $this->getPost("quiz_id");
        if (!preg_match('/^[a-f0-9]{12}$/', $quizId) || $this->getSecret()=="") return "";
        $meta = $this->readQuizMeta($quizId);
        $name = $this->cleanName($this->getPost("quiz_name"));
        if (!$meta || $name=="") return "";
        $lines = $this->readQuizFile($meta["file"]);
        if (!$lines) return "";
        $quiz = $this->parseQuiz($lines);
        if (!$quiz["hasEssays"] || $this->getScheduleState($quiz["settings"])!="open") return "";
        $path = isset($_SERVER["REQUEST_URI"]) ? parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) : "";
        if (!is_string($path) || $path=="" || $path[0]!="/") return "";
        $this->startEssayAttempt($quizId, $quiz, $name);
        return $path;
    }

    // Store the essay answers of a submitted attempt with its result. Returns false when they could not be stored.
    // $claim: true when the submission was kept as the first one (see claimAttempt), otherwise it gets no certificate.
    private function storeEssays($quizId, $attempt, $token, $claim) {
        $meta = $this->readQuizMeta($quizId);
        $lines = $meta ? $this->readQuizFile($meta["file"]) : false;
        if (!$lines) return false;
        $quiz = $this->parseQuiz($lines);
        $name = $this->getAttemptName($quizId, $attempt);
        $data = $this->parseResult($quizId, $token);
        $result = $data ? $this->evaluateResult($quiz, $quizId, $data) : null;
        if (!$quiz["hasEssays"] || $name=="" || !$result) return false;
        $numbers = $this->getDisplayNumbers($quiz, $result["seed"]);
        $posted = isset($_POST["essay"]) && is_array($_POST["essay"]) ? $_POST["essay"] : array();
        $items = array();
        foreach ($quiz["questions"] as $index=>$question) {
            if ($question["essay"]===null) continue;
            $answer = $this->normalizeEssay(isset($posted[$index]) ? $posted[$index] : "");
            $items[] = array("index"=>$index, "no"=>$numbers[$index], "question"=>$this->cleanUtf8($question["text"]), "answer"=>$answer,
                "words"=>$this->countWords($answer), "min"=>$question["essay"]["min"], "max"=>$question["essay"]["max"]);
        }
        usort($items, function($a, $b) { return $a["no"]-$b["no"]; });
        $auto = array(1=>"time", 2=>"away");
        $essays = array("attempt"=>$result["nonce"], "time"=>date("Y-m-d H:i:s"), "auto"=>isset($auto[$data["x"]]) ? $auto[$data["x"]] : "",
            "late"=>$result["isLate"], "items"=>$items);
        $device = preg_match('/^[a-f0-9]{16,32}$/', $this->getPost("quiz_device")) ? $this->getPost("quiz_device") : "none";
        if ($claim!==true) $result["meets"] = false;
        return $this->saveEssayRecord($meta["key"], $result, $name, $device, $meta["title"], $essays);
    }

    // One row per person (full name + device stamp) and quiz. The certificate part keeps the best result: a certificate
    // comes before none, then the higher score. The essay answers are always the newest ones.
    private function saveEssayRecord($recordKey, $result, $name, $device, $title, $essays) {
        $directory = $this->getDataDirectory(true);
        if ($directory===false) return false;
        $lock = $this->lockData($directory, true);
        if (!$lock) return false;
        $file = $directory.$recordKey."-essays.csv";
        $content = is_file($file) ? @file_get_contents($file) : "";
        $ok = false;
        if (is_string($content) && ($content==="" || $this->getHeaderType($file)=="essays") && strlen($content)<self::FILE_MAX) {
            $identity = $this->getIdentity($name, $device);
            $old = $content!=="" ? $this->findLine($content, "identity", $identity) : null;
            $meets = $result["meets"] && !$result["isLate"] ? 1 : 0;
            $replace = true;
            if ($old) {
                $oldCertificate = $old["record"]["certificate_no"]!="" ? 1 : 0;
                $replace = $meets>$oldCertificate || ($meets==$oldCertificate && $result["score"]>floatval($old["record"]["score"]));
            }
            if ($replace) {
                $record = array("issued"=>date("Y-m-d H:i:s"), "certificate_no"=>$meets ? $this->getUniqueNumber($directory, $result["nonce"], $name) : "",
                    "name"=>$name, "device"=>$device==="none" ? "" : substr($this->sign("device|".$device), 0, 6),
                    "minutes"=>$this->formatNumber(round(($result["submitted"]-$result["start"])/60, 1)), "score"=>$this->formatNumber($result["score"]),
                    "quiz_title"=>$title, "attempt"=>$result["nonce"], "parts"=>$this->encodeParts($result["certParts"]), "identity"=>$identity);
            } else {
                $record = $old["record"];
            }
            $record["essays"] = $this->encodeEssays($essays);
            if ($old) $content = substr($content, 0, $old["start"]).substr($content, $old["end"]+1);
            if ($content==="") $content = $this->toCsvLine($this->getRecordColumns(true));
            if (substr($content, -1)!=="\n") $content .= "\n";
            $content .= $this->toCsvLine($this->getRecordRow($record, true));
            $ok = $record["essays"]!=="" && $this->writeContent($file, $content);
        }
        $this->unlockData($lock);
        return $ok;
    }

    // Row of the essay record file that holds the certificate or the essay answers of an attempt
    private function findEssayRecord($recordKey, $nonce) {
        $directory = $this->getDataDirectory(false);
        $file = $directory!==false ? $directory.$recordKey."-essays.csv" : "";
        $content = $file!="" && is_file($file) ? @file_get_contents($file) : false;
        if (!is_string($content) || $nonce=="") return null;
        $line = $this->findLine($content, "essay_attempt", $nonce);
        if (!$line) $line = $this->findLine($content, "attempt", $nonce);
        return $line ? $line["record"] : null;
    }

    // Essay answers in one cell: JSON on one line (line breaks of the answers become \n). Quotes and backslashes are
    // written as " and \, so the cell never holds a backslash before a quote and stays plain CSV for any program.
    private function encodeEssays($essays) {
        $json = json_encode($essays, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_QUOT);
        return is_string($json) ? str_replace("\\\\", "\\u005c", $json) : "";
    }

    private function decodeEssays($text) {
        $data = json_decode((string)$text, true);
        if (!is_array($data) || !isset($data["attempt"], $data["time"], $data["items"]) || !is_string($data["attempt"]) || !is_array($data["items"])) return null;
        $items = array();
        foreach ($data["items"] as $item) {
            if (!is_array($item)) continue;
            $items[] = array("index"=>isset($item["index"]) ? intval($item["index"]) : -1, "no"=>isset($item["no"]) ? intval($item["no"]) : 0,
                "question"=>isset($item["question"]) ? (string)$item["question"] : "", "answer"=>isset($item["answer"]) ? (string)$item["answer"] : "",
                "words"=>isset($item["words"]) ? intval($item["words"]) : 0, "min"=>isset($item["min"]) ? intval($item["min"]) : 0,
                "max"=>isset($item["max"]) ? intval($item["max"]) : 0);
        }
        return array("attempt"=>$data["attempt"], "time"=>(string)$data["time"], "auto"=>isset($data["auto"]) ? (string)$data["auto"] : "",
            "late"=>!empty($data["late"]), "items"=>$items);
    }

    // Words of an essay answer: runs of characters between spaces and line breaks (the same rule as in quiz.js)
    private function countWords($text) {
        $count = preg_match_all('/[^\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', $text);
        return $count===false ? 0 : $count;
    }

    // An essay answer as it is stored: text only, line breaks as \n, no control characters, at most ESSAY_CHARS characters
    private function normalizeEssay($text) {
        if (!is_string($text) || !preg_match("//u", $text)) return "";
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', "", $text);
        if (preg_match('/^.{0,'.self::ESSAY_CHARS.'}/su', $text, $matches)) $text = $matches[0];
        return trim($text);
    }

    // Text of the quiz file for the record file: invalid characters (a file not saved as UTF-8) are replaced
    private function cleanUtf8($text) {
        if (preg_match("//u", $text)) return $text;
        return function_exists("mb_convert_encoding") ? mb_convert_encoding($text, "UTF-8", "UTF-8") : preg_replace('/[\x80-\xFF]/', "?", $text);
    }

    // Displayed number of every question in the order of an attempt
    private function getDisplayNumbers($quiz, $seed) {
        $numbers = array();
        $this->renderSections($quiz, $this->getOrder($quiz, $seed), false, function($index, $displayed) use (&$numbers) {
            $numbers[$index] = $displayed;
            return "";
        });
        return $numbers;
    }

    // Essay question on the quiz page: the word limit, a text field without help from the browser, and the word count
    private function renderEssayCard($quizId, $index, $number, $question) {
        $essay = $question["essay"];
        $labelId = "quiz-{$quizId}-q{$index}";
        $limit = $essay["min"]>0 ? str_replace(array("@min", "@max"), array((string)$essay["min"], (string)$essay["max"]), $this->text("quizEssayLimitRange")) :
            str_replace("@max", (string)$essay["max"], $this->text("quizEssayLimitMax"));
        $chars = min(self::ESSAY_CHARS, max(1000, $essay["max"]*30));
        $card = "<div class=\"quiz-q quiz-essay\" data-index=\"{$index}\" data-min=\"{$essay["min"]}\" data-max=\"{$essay["max"]}\">\n";
        $card .= $this->renderQuestionText($question, $number, $labelId);
        $card .= "<p class=\"quiz-essay-limit\">".$this->e($limit)."</p>\n";
        $card .= "<textarea name=\"essay[{$index}]\" aria-labelledby=\"{$labelId}\" rows=\"6\" maxlength=\"{$chars}\"".
            " spellcheck=\"false\" autocomplete=\"off\"".
            " data-gramm=\"false\" data-gramm_editor=\"false\" data-enable-grammarly=\"false\" data-lt-active=\"false\"></textarea>\n";
        $card .= "<div class=\"quiz-essay-meta\"><span class=\"quiz-essay-time\" hidden></span><span class=\"quiz-essay-count\" aria-live=\"polite\">".
            $this->e(str_replace(array("@count", "@max"), array("0", (string)$essay["max"]), $this->text("quizEssayWords")))."</span></div>\n";
        return $card."</div>\n";
    }

    // Mistakes in the quiz file are shown to a logged-in user of the editor of Yellow, never to students
    private function renderSetupNotices($quiz) {
        if (!$this->isEditorUser()) return "";
        $output = "";
        if ($this->getDataPath()===false) $output .= "<div class=\"quiz-setup-notice\" role=\"note\">".$this->e($this->text("quizDataUnsafe"))."</div>\n";
        foreach ($quiz["notices"] as $notice) {
            $values = array_map("strval", array_merge(array("@line"=>$notice[0]), $notice[2]));
            $text = strtr($this->text($notice[1]), $values);
            $output .= "<div class=\"quiz-setup-notice\" role=\"note\">".$this->e($text)."</div>\n";
        }
        return $output;
    }

    // Logged-in user of the editor of Yellow: the editor tells the core who is logged in (only on its own pages)
    private function isEditorUser() {
        return $this->getEditorEmail()!="";
    }

    private function getEditorEmail() {
        $user = isset($this->yellow->user) ? $this->yellow->user : null;
        return is_object($user) && isset($user->email) && is_string($user->email) ? $user->email : "";
    }

    // Kind of a record file by its first line: "base" (certificates), "essays" (certificates and essay answers),
    // "" for any other column list (such a file is never written to)
    private function getHeaderType($file) {
        $handle = @fopen($file, "r");
        if (!$handle) return "";
        $line = fgets($handle);
        fclose($handle);
        if (!is_string($line)) return "";
        $line = rtrim($line, "\r\n");
        if ($line===rtrim($this->toCsvLine($this->getRecordColumns(false)), "\n")) return "base";
        if ($line===rtrim($this->toCsvLine($this->getRecordColumns(true)), "\n")) return "essays";
        return "";
    }

    // Replace one line (found by its certificate number) and add the new record, the file is written in one step
    private function replaceRecord($file, $number, $record) {
        $content = @file_get_contents($file);
        if ($content===false) return false;
        $line = $this->findLine($content, "certificate_no", $number);
        if ($line) $content = substr($content, 0, $line["start"]).substr($content, $line["end"]+1);
        if ($content!=="" && substr($content, -1)!=="\n") $content .= "\n";
        $content .= $this->toCsvLine($this->getRecordRow($record, false));
        return $this->writeContent($file, $content);
    }

    // Write a whole file through a temporary file, so readers never see a half written file
    private function writeContent($file, $content) {
        $temporary = $file.".tmp-".bin2hex(random_bytes(4));
        $written = @file_put_contents($temporary, $content);
        if ($written!==strlen($content) || !@rename($temporary, $file)) {
            @unlink($temporary);
            return false;
        }
        return true;
    }

    // Certificate number: 10 characters, made different when the same number is already stored
    private function getUniqueNumber($directory, $nonce, $name) {
        $files = (array)glob($directory."*.csv");
        for ($round=0; $round<10; $round++) {
            $number = strtoupper(substr($this->sign("code|".$nonce."|".$name.($round ? "|".$round : "")), 0, 10));
            if (!$this->searchFiles($files, "certificate_no", $number)) break;
        }
        return $number;
    }

    // Append one record, the file is cut back to its old size if writing fails (for example a full disk)
    private function appendRecord($file, $record) {
        $handle = @fopen($file, "c+");
        if (!$handle) return false;
        fseek($handle, 0, SEEK_END);
        $size = ftell($handle);
        $text = $this->toCsvLine($this->getRecordRow($record, false));
        if ($size==0) {
            $text = $this->toCsvLine($this->getRecordColumns(false)).$text;
        } else {
            fseek($handle, $size-1);
            if (fread($handle, 1)!=="\n") $text = "\n".$text; // a file edited by hand may end without a line break
            fseek($handle, 0, SEEK_END);
        }
        $written = fwrite($handle, $text);
        $ok = $written===strlen($text) && fflush($handle);
        if (!$ok) ftruncate($handle, $size);
        fclose($handle);
        return $ok;
    }

    // Columns of a record file; files of quizzes with essay questions have the column "essays" at the end
    private function getRecordColumns($essays = false) {
        $columns = array("issued", "certificate_no", "name", "device", "minutes", "score", "quiz_title", "attempt", "parts", "identity");
        if ($essays) $columns[] = "essays";
        return $columns;
    }

    // Mastery per category stored in one cell, for example "Word Formation=90;Phonemes=70"
    private function encodeParts($parts) {
        $list = array();
        foreach ($parts as $part) {
            if (is_array($part) && isset($part[0], $part[1]) && is_numeric($part[1])) {
                $list[] = str_replace(array("=", ";"), " ", (string)$part[0])."=".$this->formatNumber($part[1]);
            }
        }
        return implode(";", $list);
    }

    private function decodeParts($text) {
        $parts = array();
        foreach (explode(";", (string)$text) as $item) {
            $pair = explode("=", $item, 2);
            if (count($pair)==2 && trim($pair[0])!="" && is_numeric($pair[1])) $parts[] = array(trim($pair[0]), floatval($pair[1]));
        }
        return $parts;
    }

    // Record values in column order; names and titles are kept on one line and protected against spreadsheet formulas
    private function getRecordRow($record, $essays = false) {
        $row = array();
        foreach ($this->getRecordColumns($essays) as $column) {
            $value = isset($record[$column]) ? (string)$record[$column] : "";
            if ($column=="name" || $column=="quiz_title") $value = $this->csvSafe(trim(preg_replace('/[\r\n\t]+/', " ", $value)));
            $row[] = $value;
        }
        return $row;
    }

    private function toCsvLine($fields) {
        $buffer = fopen("php://temp", "w+");
        fputcsv($buffer, $fields, ",", "\"", "\\");
        rewind($buffer);
        $line = stream_get_contents($buffer);
        fclose($buffer);
        return $line;
    }

    // Return certificate data for quiz.js
    private function getCertificateData($record) {
        return array("ok"=>true, "name"=>$record["name"], "code"=>$record["certificate_no"],
            "date"=>substr($record["issued"], 0, 10), "title"=>$record["quiz_title"], "site"=>$this->yellow->system->get("sitename"),
            "score"=>is_numeric($record["score"]) ? floatval($record["score"]) : $record["score"], "parts"=>$this->decodeParts($record["parts"]));
    }

    // Find a stored certificate of a quiz by column value
    private function findRecord($recordKey, $column, $value) {
        $directory = $this->getDataDirectory(false);
        $files = array();
        if ($directory!==false) $files[] = $directory.$recordKey.".csv";
        return $this->searchFiles($files, $column, $value);
    }

    private function searchFiles($files, $column, $value) {
        if ($value=="") return null;
        foreach ($files as $file) {
            $content = is_file($file) ? @file_get_contents($file) : false;
            $line = is_string($content) ? $this->findLine($content, $column, $value) : null;
            if ($line) return $line["record"];
        }
        return null;
    }

    // Fast search in the content of a record file: look for the value as text, then parse only the lines that contain it.
    // Returns the first record that is not expired with the value in the column, and where its line starts and ends.
    private function findLine($content, $column, $value) {
        $end = strpos($content, "\n");
        $header = str_getcsv($end===false ? $content : substr($content, 0, $end), ",", "\"", "\\");
        $offset = 0;
        while (($position = strpos($content, $value, $offset))!==false) {
            $offset = $position+1;
            $lineStart = strrpos(substr($content, 0, $position), "\n");
            if ($lineStart===false) continue; // value found in the header line
            $lineEnd = strpos($content, "\n", $position);
            if ($lineEnd===false) $lineEnd = strlen($content);
            $record = $this->toRecord($header, str_getcsv(substr($content, $lineStart+1, $lineEnd-$lineStart-1), ",", "\"", "\\"));
            if ($record && $record[$column]===$value && !$this->isRecordExpired($record)) {
                return array("record"=>$record, "start"=>$lineStart+1, "end"=>$lineEnd);
            }
        }
        return null;
    }

    // Map a CSV row to a record using the header, returns null for rows without the required columns.
    // In a file with essays a row may have no certificate: the essay answers of a student who has no certificate (yet).
    private function toRecord($header, $row) {
        $record = array();
        $header = array_map("trim", $header);
        $withEssays = in_array("essays", $header, true);
        foreach ($this->getRecordColumns($withEssays) as $column) {
            $index = array_search($column, $header, true);
            $record[$column] = $index!==false && isset($row[$index]) ? trim((string)$row[$index]) : "";
        }
        if ($record["issued"]=="" || $record["attempt"]=="" || ($record["certificate_no"]=="" && !$withEssays)) return null;
        $record["name"] = $this->displayName($record["name"]);
        $record["quiz_title"] = $this->displayName($record["quiz_title"]);
        $essays = $withEssays ? $this->decodeEssays($record["essays"]) : null;
        $record["essay_attempt"] = $essays ? $essays["attempt"] : "";
        $record["essay_time"] = $essays ? $essays["time"] : "";
        return $record;
    }

    // Read records that are not expired (columns are matched by the header line) and count all data rows
    private function readRecords($file) {
        $records = array();
        $rows = 0;
        $handle = is_file($file) ? @fopen($file, "r") : false;
        if (!$handle) return array("records"=>$records, "rows"=>$rows);
        $header = fgetcsv($handle, 0, ",", "\"", "\\");
        if (is_array($header)) {
            while (($row = fgetcsv($handle, 0, ",", "\"", "\\"))!==false) {
                $rows++;
                $record = $this->toRecord($header, $row);
                if ($record && !$this->isRecordExpired($record)) $records[] = $record;
            }
        }
        fclose($handle);
        return array("records"=>$records, "rows"=>$rows);
    }

    // Write records through a temporary file, so readers never see a half written file
    private function writeRecords($file, $records, $essays) {
        if (!count($records)) return !is_file($file) || @unlink($file);
        $content = $this->toCsvLine($this->getRecordColumns($essays));
        foreach ($records as $record) $content .= $this->toCsvLine($this->getRecordRow($record, $essays));
        return $this->writeContent($file, $content);
    }

    // Number of days records (certificates and essay answers) are kept, 0 = forever
    private function getKeepDays() {
        if ($this->keepDays===null) {
            $value = trim((string)$this->yellow->system->get("quizCertificateKeepDays"));
            if ($value=="" || !is_numeric($value) || floatval($value)<0) $this->keepDays = 30;
            else $this->keepDays = floatval($value)==0 ? 0 : max(1, (int)ceil(floatval($value)));
        }
        return $this->keepDays;
    }

    // A record expires a number of days after its last change: the certificate or, later, the newest essay answers
    private function isRecordExpired($record) {
        $days = $this->getKeepDays();
        if ($days==0) return false;
        $time = strtotime($record["issued"]);
        $essayTime = $record["essay_time"]!="" ? strtotime($record["essay_time"]) : false;
        if ($essayTime!==false && ($time===false || $essayTime>$time)) $time = $essayTime;
        return $time===false || $time+$days*86400<time();
    }

    // Remove expired records, at most once per SWEEP_INTERVAL; a file with other columns is left untouched
    private function sweepRecords() {
        $directory = $this->getDataDirectory(false);
        if ($directory===false) return;
        $marker = $directory."index.html";
        if (is_file($marker) && time()-filemtime($marker)<self::SWEEP_INTERVAL) return;
        $lock = $this->lockData($directory, false);
        if (!$lock) return;
        foreach ((array)glob($directory."*.csv") as $file) {
            $type = $this->getHeaderType($file);
            if ($type=="") continue;
            $read = $this->readRecords($file);
            if (count($read["records"])!=$read["rows"]) $this->writeRecords($file, $read["records"], $type=="essays");
        }
        // codes of submitted attempts: only those that have not expired stay
        $file = $directory.self::SUBMITTED_FILE;
        $content = is_file($file) ? @file_get_contents($file) : false;
        if (is_string($content)) {
            $kept = "";
            foreach (explode("\n", $content) as $line) {
                if (preg_match('/^[a-f0-9]{20} (\d{9,11})$/', $line, $matches) && intval($matches[1])>=time()) $kept .= $line."\n";
            }
            if ($kept==="") @unlink($file);
            elseif ($kept!==$content) $this->writeContent($file, $kept);
        }
        foreach ((array)glob($directory."*.tmp-*") as $file) {
            if (time()-filemtime($file)>3600) @unlink($file);
        }
        @touch($marker);
        $this->unlockData($lock);
    }

    // Data directory for the records, closed to web access; without a setting it is "quiz-data/" in the quiz folder
    private function getDataDirectory($create) {
        $directory = $this->getDataPath();
        if ($directory===false) return false;
        if (!is_dir($directory)) {
            if (!$create) return false;
            if (!@mkdir($directory, 0775, true) && !is_dir($directory)) return false;
        }
        if (!is_file($directory.".htaccess")) {
            @file_put_contents($directory.".htaccess", "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        if (!is_file($directory."index.html") || !filesize($directory."index.html")) {
            @file_put_contents($directory."index.html", self::DATA_MARKER);
        }
        return is_writable($directory) ? $directory : false;
    }

    // Folder of the record files. Without own setting it is "quiz-data/" in the quiz folder, but only when the quiz folder
    // lies in the content or system folder of Yellow, which the web server never hands out; otherwise nothing is stored.
    private function getDataPath() {
        $directory = trim((string)$this->yellow->system->get("quizDataDirectory"));
        if ($directory=="") {
            $quizDirectory = rtrim(trim((string)$this->yellow->system->get("quizDirectory")), "/");
            if (!$this->isClosedFolder($quizDirectory)) return false;
            $directory = $quizDirectory."/quiz-data/";
        }
        return rtrim($directory, "/")."/";
    }

    private function isClosedFolder($path) {
        $real = $path!="" ? realpath($path) : false;
        if ($real===false) return false;
        foreach (array("coreContentDirectory", "coreSystemDirectory") as $setting) {
            $base = realpath(rtrim((string)$this->yellow->system->get($setting), "/"));
            if ($base!==false && strpos($real.DIRECTORY_SEPARATOR, $base.DIRECTORY_SEPARATOR)===0) return true;
        }
        return false;
    }

    // Lock for writing records, index.html is used as lock file
    private function lockData($directory, $wait) {
        $handle = @fopen($directory."index.html", "c");
        if (!$handle) return null;
        if (!flock($handle, $wait ? LOCK_EX : LOCK_EX|LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    private function unlockData($handle) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    // Record file name: quiz file name + part of the quiz id, so the same quiz on two pages gets two files
    private function getRecordKey($fileName, $quizId) {
        $name = strtolower(preg_replace('/\.[A-Za-z0-9]+$/', "", $fileName));
        $name = trim(preg_replace('/[^a-z0-9]+/', "-", $name), "-");
        return substr($name, 0, 60)."-".substr($quizId, 0, 6);
    }

    // Return secret: QuizSecret setting (16+ characters) or a random key stored in quiz-secret.php
    private function getSecret() {
        if ($this->secret!==null) return $this->secret;
        $secret = trim((string)$this->yellow->system->get("quizSecret"));
        if (strlen($secret)<16) {
            $secret = "";
            $handle = @fopen(dirname(__FILE__)."/quiz-secret.php", "c+");
            if ($handle) {
                flock($handle, LOCK_EX);
                $content = stream_get_contents($handle);
                if (preg_match('/return "([a-f0-9]{64})";/', (string)$content, $matches)) {
                    $secret = $matches[1];
                } else {
                    $value = bin2hex(random_bytes(32));
                    $text = "<?php\n// Generated by the quiz extension. Keep it private, do not change it while quizzes are running.\nreturn \"{$value}\";\n";
                    ftruncate($handle, 0);
                    rewind($handle);
                    if (fwrite($handle, $text)===strlen($text) && fflush($handle)) $secret = $value;
                }
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
        $this->secret = $secret;
        return $secret;
    }

    private function sign($text) {
        return hash_hmac("sha256", $text, $this->getSecret());
    }

    // Stop spreadsheet programs from treating a value as a formula
    private function csvSafe($text) {
        return preg_match('/^[=+\-@\t\r]/', (string)$text) ? "'".$text : (string)$text;
    }

    private function displayName($text) {
        return preg_match('/^\'[=+\-@\t\r]/', $text) ? substr($text, 1) : $text;
    }

    // Texts for quiz.js by name, and the site name (printed instead of the quiz)
    private function getClientTexts($names) {
        $texts = array();
        foreach ($names as $name) $texts[$name] = $this->text("quiz".ucfirst($name));
        $texts["site"] = (string)$this->yellow->system->get("sitename");
        return $texts;
    }

    // Every text is English, whatever the language of the site or the page.
    // A text can be changed in yellow-language.ini, under "Language: en".
    private function text($key) {
        return $this->yellow->language->getText($key, "en");
    }

    private function getPost($key) {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : "";
    }

    private function getQuery($key) {
        return isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : "";
    }

    private function e($text) {
        return htmlspecialchars((string)$text, ENT_QUOTES, "UTF-8");
    }

    private function toJson($data) {
        return json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    // One text line of the quiz file: fenced block, pasted embed code or formatted text
    private function renderLine($line) {
        if ($line!=="" && $line[0]==="\x01") {
            $parts = explode("\x01", $line, 3);
            $kind = isset($parts[1]) ? $parts[1] : "";
            $body = isset($parts[2]) ? $parts[2] : "";
            if ($kind=="mermaid") return "<div class=\"quiz-media quiz-diagram\"><pre class=\"mermaid quiz-mermaid\">".$this->e($body)."</pre></div>";
            if ($kind=="chart") return "<div class=\"quiz-media quiz-chart\"><canvas data-config=\"".$this->e($body)."\"></canvas></div>";
            if ($kind=="html") return $this->renderEmbed($body);
            return "<pre class=\"quiz-code\"><code>".$this->e($body)."</code></pre>";
        }
        if (preg_match('/^\s*<(iframe|audio|video)\b/i', $line)) return $this->renderEmbed($line);
        return $this->toHTML($line, true);
    }

    // Pasted <iframe>, <audio> or <video> code is rebuilt from its address only, with the quiz rules:
    // no new tabs, no full screen, no picture-in-picture, no download, nothing played before it is wanted.
    // Videos are 16:9 and as wide as their place; other embedded pages keep the height that was written.
    private function renderEmbed($html) {
        if (!preg_match('/<(iframe|audio|video)\b([^>]*)>/i', $html, $matches)) return "<p>".$this->e($html)."</p>";
        $tag = strtolower($matches[1]);
        $attributes = $this->parseAttributes($matches[2]);
        $src = $this->cleanUrl(isset($attributes["src"]) ? $attributes["src"] : "");
        if ($tag=="iframe") {
            if ($src=="") return "";
            // archive.org: a sound or video file is played by the player of the browser, which the quiz can pause
            if (preg_match('~^https?://(?:www\.)?archive\.org/embed/([^?#]+)~i', $src, $matches)) {
                $path = str_replace(array("+", " "), "%20", $matches[1]);
                $kind = $this->getFileKind($path);
                if ($kind=="audio") return $this->renderAudio("https://archive.org/download/".$path, "");
                if ($kind=="video") return $this->renderVideo("https://archive.org/download/".$path, "", "");
            }
            $size = "";
            foreach (array("width", "height") as $name) {
                if (isset($attributes[$name]) && preg_match('/^\d{1,4}(%|px)?$/', $attributes[$name])) $size .= " {$name}=\"".$attributes[$name]."\"";
            }
            return $this->renderFrame($this->getFrameUrl($src), isset($attributes["title"]) ? $attributes["title"] : "", $size);
        }
        $sources = "";
        if (preg_match_all('/<source\b([^>]*)>/i', $html, $list)) {
            foreach ($list[1] as $item) {
                $source = $this->parseAttributes($item);
                $url = $this->cleanUrl(isset($source["src"]) ? $source["src"] : "");
                if ($url=="") continue;
                $type = isset($source["type"]) && preg_match('/^[a-z]+\/[a-z0-9.+\-]+$/i', $source["type"]) ? " type=\"".$source["type"]."\"" : "";
                $sources .= "<source src=\"".$this->e($url)."\"{$type} />";
            }
        }
        if ($src=="" && $sources=="") return "";
        if ($tag=="audio") return $this->renderAudio($src, $sources);
        return $this->renderVideo($src, $sources, $this->cleanUrl(isset($attributes["poster"]) ? $attributes["poster"] : ""));
    }

    // Short form ![text](address): YouTube and Vimeo become players, sound and video files get a player, anything else is an image
    private function renderShortMedia($alt, $escapedUrl, $original) {
        $url = $this->cleanUrl(html_entity_decode($escapedUrl, ENT_QUOTES, "UTF-8"));
        if ($url=="") return $original;
        if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^#]*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_\-]{11})~', $url, $m)) {
            return $this->renderFrame($this->getFrameUrl("https://www.youtube-nocookie.com/embed/".$m[1]), html_entity_decode($alt, ENT_QUOTES, "UTF-8"), "");
        }
        if (preg_match('~^https?://(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
            return $this->renderFrame("https://player.vimeo.com/video/".$m[1], html_entity_decode($alt, ENT_QUOTES, "UTF-8"), "");
        }
        $path = parse_url($url, PHP_URL_PATH); // null for an address without a path
        $kind = $this->getFileKind(is_string($path) ? $path : "");
        if ($kind=="audio") return $this->renderAudio($url, "");
        if ($kind=="video") return $this->renderVideo($url, "", "");
        return "<img src=\"".$this->e($url)."\" alt=\"{$alt}\" loading=\"lazy\" />";
    }

    // "audio" or "video" by the extension of a file name, "" for anything else
    private function getFileKind($path) {
        $extension = pathinfo(strtolower($path), PATHINFO_EXTENSION);
        if (in_array($extension, array("mp3", "m4a", "aac", "ogg", "oga", "opus", "wav", "flac"))) return "audio";
        if (in_array($extension, array("mp4", "m4v", "webm", "ogv", "mov"))) return "video";
        return "";
    }

    // iframe: loaded when it comes near the screen; the sandbox blocks new tabs and leaving the page, full screen is not allowed.
    // YouTube and Vimeo players are 16:9 and can be paused by the quiz; other pages keep the size that was written.
    private function renderFrame($src, $title, $size) {
        $player = preg_match('~^https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/~', $src) ? "youtube" : (preg_match('~^https?://player\.vimeo\.com/video/~', $src) ? "vimeo" : "");
        $output = "<div class=\"quiz-media quiz-frame".($player!="" ? " quiz-player" : "")."\"><iframe src=\"".$this->e($src)."\" title=\"".$this->e($title!="" ? $title : "Embedded content")."\"";
        $output .= $player!="" ? " data-player=\"{$player}\"" : $size;
        return $output." loading=\"lazy\" sandbox=\"allow-scripts allow-same-origin allow-forms\" allow=\"autoplay; encrypted-media\"></iframe></div>";
    }

    // Sound and video files: the player of the browser; only the length is loaded before playing. Yellow removes the
    // attributes for download, full screen and playing inside the page; quiz.js sets them.
    private function renderAudio($src, $sources) {
        return "<div class=\"quiz-media\"><audio class=\"quiz-audio\" controls preload=\"metadata\"".($src!="" ? " src=\"".$this->e($src)."\"" : "").">{$sources}</audio></div>";
    }

    private function renderVideo($src, $sources, $poster) {
        return "<div class=\"quiz-media quiz-movie\"><video class=\"quiz-video\" controls preload=\"metadata\"".
            ($poster!="" ? " poster=\"".$this->e($poster)."\"" : "").($src!="" ? " src=\"".$this->e($src)."\"" : "").">{$sources}</video></div>";
    }

    // YouTube players get no full screen button, play inside the page and accept the pause of the quiz
    private function getFrameUrl($src) {
        if (preg_match('~^https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/~', $src)) {
            $src .= (strpos($src, "?")===false ? "?" : "&")."fs=0&playsinline=1&rel=0&enablejsapi=1";
        }
        return $src;
    }

    // Attributes of an HTML tag as name => value
    private function parseAttributes($text) {
        $attributes = array();
        if (preg_match_all('/([a-zA-Z][\w:\-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $text, $list, PREG_SET_ORDER)) {
            foreach ($list as $item) {
                $value = isset($item[4]) ? $item[4] : (isset($item[3]) ? $item[3] : $item[2]); // unquoted, single or double quotes
                $attributes[strtolower($item[1])] = html_entity_decode($value, ENT_QUOTES, "UTF-8");
            }
        }
        return $attributes;
    }

    // Only web addresses (http, https) and addresses on this site (starting with one "/") are used
    private function cleanUrl($url) {
        $url = trim((string)$url);
        if (preg_match('~^https?://[^\s"\'<>`]+$~i', $url)) return $url;
        if (preg_match('~^/(?!/)[^\s"\'<>`]*$~', $url)) return $url;
        return "";
    }

    // Diagram and chart libraries: only for quizzes that use them; a library already on the page is used first
    private function getLibraryAttributes($quiz) {
        $output = "";
        if ($quiz["hasMermaid"]) $output .= " data-mermaid-url=\"".$this->e($this->cleanUrl($this->yellow->system->get("quizMermaidUrl")))."\"";
        if ($quiz["hasChart"]) $output .= " data-chart-url=\"".$this->e($this->cleanUrl($this->yellow->system->get("quizChartUrl")))."\"";
        return $output;
    }

    // Micro markdown-like formatting. Links ([text](address), web addresses) open in a new tab, or are plain text on the quiz page.
    public function toHTML($text, $p, $heading = true) {
        $text = htmlspecialchars(trim($text), ENT_QUOTES, "UTF-8");
        if ($text==="") return "";
        $text = preg_replace_callback('/\\\[\\\n]/', function($m) { return $m[0]=="\\\\" ? "\\" : "<br />\n"; }, $text);
        $text = preg_replace("/\*\*(.+?)\*\*/", "<b>$1</b>", $text);
        $text = preg_replace("/\*(.+?)\*/", "<i>$1</i>", $text);
        $text = preg_replace_callback("/!\[(.*?)\]\(((?:https?:\/\/|\/)[^ )]+)\)/", function($m) { return $this->renderShortMedia($m[1], $m[2], $m[0]); }, $text);
        $text = preg_replace_callback('/\[(.*?)\]\((https?:\/\/[^ )]+|\/(?![\/\\\\])[^ )\\\\]*)\)/', function($m) { return $this->renderLink($m[1], $m[2]); }, $text);
        $text = preg_replace_callback('/(?<![(">])(https?:\/\/[^\s<"]+)/', function($m) { return $this->renderLink($m[1], $m[1]); }, $text);
        if ($heading && preg_match('/^(#{1,6})(?:\s+(.*))?$/s', $text, $matches)) { // "#" to "######" and a space; "###" alone shows nothing
            if (!isset($matches[2]) || trim($matches[2])==="") return "";
            $level = strlen($matches[1]);
            $text = "<h{$level}>".$matches[2]."</h{$level}>";
        } elseif ($p && strpos($text, "<div class=\"quiz-media")===false) {
            $text = "<p>".$text."</p>";
        } elseif ($p) { // a player is a block of its own: inside a paragraph the browser would leave empty paragraphs around it
            $output = "";
            foreach (preg_split('~(<div class="quiz-media[^"]*">.*?</div>)~s', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
                if (strncmp($part, "<div class=\"quiz-media", 22)==0) $output .= $part;
                elseif (trim($part)!=="") $output .= "<p>".trim($part)."</p>";
            }
            $text = $output;
        }
        return $text;
    }

    // Link of the quiz text: web addresses and addresses on this site (with the base of the site), always in a new tab;
    // on the quiz page only its text
    private function renderLink($label, $url) {
        if ($this->linkMode=="text") return $label;
        $href = $url[0]=="/" ? $this->e($this->yellow->system->get("coreServerBase")).$url : $url;
        return "<a href=\"".$href."\" target=\"_blank\" rel=\"noopener noreferrer\">".$label."</a>";
    }

    // Handle page extra data: quiz.css and quiz.js only on pages that show a quiz, in the header (the content of the page
    // is read before it) or, for a quiz shown later by the layout (for example a list of blog posts), in the footer
    public function onParsePageExtra($page, $name) {
        $output = null;
        if (($name=="header" || $name=="footer") && $this->assets=="needed") {
            $this->assets = "sent";
            // the asset folder of Yellow (Yellow 0.9.1 calls it coreExtensionLocation, later versions coreAssetLocation)
            $location = $this->yellow->system->get("coreAssetLocation");
            if ($location=="") $location = $this->yellow->system->get("coreExtensionLocation");
            $assetLocation = $this->yellow->system->get("coreServerBase").$location;
            $version = rawurlencode(self::VERSION);
            $output .= "<script type=\"text/javascript\" defer=\"defer\" src=\"{$assetLocation}quiz.js?v={$version}\"></script>\n";
            $output .= "<link rel=\"stylesheet\" type=\"text/css\" media=\"all\" href=\"{$assetLocation}quiz.css?v={$version}\" />\n";
        }
        return $output;
    }
}
