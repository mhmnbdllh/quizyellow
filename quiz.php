<?php
// Quiz extension (customised build), based on YellowQuiz 0.9.1
// - Settings per quiz in the quiz file: time, penalty, shuffle, review, pass, minimum
// - Categories with "@ Name"; score is always 0-100, every question has the same value
// - Every attempt gets a token signed by the server (random seed + start time)
// - Answer values are tokens bound to the attempt, the page never reveals the key
// - After submitting, the browser is sent to a signed result address, nothing is stored
// - The time limit is checked by the server, not only by the browser
// - Only certificates are stored: one small file per quiz, deleted automatically

class YellowQuiz {
    const VERSION = "0.9.1-custom.12";
    const GRACE = 60;                 // seconds accepted after the time limit (network delay, auto-submit)
    const MAX_LIFETIME = 604800;      // 7 days, longest time an attempt can stay open
    const CERT_WINDOW = 86400;        // a certificate can be created up to 1 day after submitting
    const SWEEP_INTERVAL = 3600;      // expired certificate records are removed at most once per hour
    const FILE_MAX = 5242880;         // 5 MB limit for one quiz record file
    const MAX_QUESTIONS = 300;        // answers of at most 300 questions fit into the result kept by the browser
    public $yellow;                   // access to API
    private $secret = null;
    private $certResponse = null;

    // Handle initialisation
    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $this->yellow->system->setDefault("quizDirectory", "media/quiz/");
        $this->yellow->system->setDefault("quizPass", "");
        $this->yellow->system->setDefault("quizCertificateKeepDays", "30");
        $this->yellow->system->setDefault("quizSecret", "");
        $this->yellow->system->setDefault("quizDataDirectory", "");
        $this->yellow->system->setDefault("quizCertificateFile", "");
        $this->yellow->system->setDefault("quizCertificateMinScore", "");
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
            "QuizCertCompleted: has successfully completed",
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
            "QuizMastery: Mastery: <b>@percent%</b>. This percentage shows how much of the material you have mastered.",
            "QuizCertMastery: demonstrating @percent% mastery of the material",
            "QuizTooLong: This quiz has more than @max questions. Please split it into smaller quizzes.",
            "QuizCertNeed: Answer @count more questions correctly to unlock your certificate.",
            "QuizCertNeedOne: Answer 1 more question correctly to unlock your certificate.",
            "QuizCertNeedPass: The pass mark is @min points.",
            "QuizCertNeedParts: Every part needs at least @minimum%. Below the minimum now: @list.",
            "QuizCertParts: Mastery by part: @list.",
            "QuizCategoryNote: Mastery per part is the percentage of questions answered correctly in that part.",
            "QuizCategoryMinimum: Each part needs at least @minimum% for the certificate.",
            "QuizPenaltyInfo: Penalty for wrong answers is on: -@points points for @count wrong answers.",
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
            "QuizIntroPass: Score of at least @pass",
            "QuizIntroPassMinimum: Score of at least @pass, and at least @minimum% in every part",
            "QuizIntroPassNone: Every attempt submitted in time",
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
            "QuizSetupError: This quiz cannot be used yet: the secret key could not be created. Set QuizSecret in the system settings.",
        ));
    }

    // Handle requests before the page is built: submissions, certificates, reprints
    public function onRequest($scheme, $address, $base, $location, $fileName) {
        if (!isset($_SERVER["REQUEST_METHOD"]) || $_SERVER["REQUEST_METHOD"]!="POST") return 0;
        if (isset($_POST["quiz_cert"])) $this->sendJson($this->issueCertificate($_POST));
        if (isset($_POST["quiz_reprint"])) $this->sendJson($this->reprintCertificate($_POST));
        if (isset($_POST["quiz_id"], $_POST["quiz_attempt"])) {
            $url = $this->getSubmissionRedirect();
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
        $this->sendResultCookies($quizId, $this->createResult($quizId, $attempt), $path);
        return $path."?result";
    }

    // Path of the quiz page for the result cookie; anything unusual falls back to "/"
    private function getCookiePath($path) {
        return is_string($path) && preg_match('#^/[A-Za-z0-9/_\-.%~]*$#', $path) ? $path : "/";
    }

    // The result stays in the browser of the student for 24 hours, only sent back to this quiz page.
    // The attempt cookie is removed: the attempt is finished.
    private function sendResultCookies($quizId, $token, $path) {
        if (headers_sent()) return;
        $path = $this->getCookiePath($path);
        $secure = isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"]!=="" && $_SERVER["HTTPS"]!=="off" ? "; Secure" : "";
        @header("Set-Cookie: yquizr_".$quizId."=".$token."; Path=".$path."; Max-Age=86400; SameSite=Lax".$secure, false);
        @header("Set-Cookie: yquiz_".$quizId."=; Path=/; Max-Age=0; SameSite=Lax".$secure, false);
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
        if ($name=="quizleaderboard" && ($type=="block" || $type=="inline")) return $this->renderLeaderboard($text);
        if (($name=="quiz" || $name=="quizcertificate") && ($type=="block" || $type=="inline")) {
            if (isset($_POST["quiz_cert"]) || isset($_POST["quiz_reprint"])) { // fallback when onRequest is not called
                $response = isset($_POST["quiz_cert"]) ? $this->issueCertificate($_POST) : $this->reprintCertificate($_POST);
                return "<script type=\"application/json\" class=\"quiz-cert-response\">".
                    json_encode($response, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)."</script>\n";
            }
            if ($this->getSecret()=="") return $this->renderNotice("", $this->text("quizSetupError"));
            $this->sweepRecords();
            if ($name=="quizcertificate") return "<div lang=\"en\" class=\"quiz-container quiz-reprint-page\">\n".$this->renderReprint(false)."</div>\n";
            list($fileName, $customTitle) = $this->getArguments($text);
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
            if (method_exists($this->yellow->page, "setHeader")) {
                $this->yellow->page->setHeader("Cache-Control", "no-store, max-age=0");
            }
            if (isset($_GET["result"])) { // result page: only shown in the browser that holds the signed result
                $cookieName = "yquizr_".$quizId;
                $data = isset($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName]) ? $this->parseResult($quizId, $_COOKIE[$cookieName]) : null;
                if ($data) return $this->renderResult($quiz, $quizId, $recordKey, $data, $title, "");
            }
            if ($this->getPost("quiz_id")===$quizId) { // fallback: onRequest did not redirect
                if ($this->getPost("quiz_client")!=="1") return $this->renderNotice($quizId, $this->text("quizJsRequired"));
                $attempt = $this->parseAttempt($quizId, $this->getPost("quiz_attempt"), $settings["time"], false);
                if (!$attempt) return $this->renderInvalid($quizId);
                $token = $this->createResult($quizId, $attempt); // quiz.js stores it in the browser like the redirect would
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
                // "Start quiz" pressed: a new attempt, unless one is running (Start pressed twice) or the quiz is not open
                if (!$retake && isset($_GET["quiz_start"]) && !$attempt && $this->getScheduleState($settings)=="open") {
                    $attempt = $this->newAttempt($quizId);
                    $this->sendAttemptCookie($quizId, $attempt, $settings["time"]);
                }
                $output = $attempt ? $this->renderForm($quiz, $quizId, $attempt) : $this->renderIntro($quiz, $quizId, $title);
            }
        }
        return $output;
    }

    // Read quiz file, only plain relative names inside the quiz directory are accepted
    private function readQuizFile($fileName) {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-.\/]*$/', $fileName) || strpos($fileName, "..")!==false) return false;
        $directory = realpath($this->yellow->system->get("quizDirectory"));
        $path = realpath($this->yellow->system->get("quizDirectory").$fileName);
        if ($directory===false || $path===false || !is_file($path)) return false;
        if (strpos($path, $directory.DIRECTORY_SEPARATOR)!==0) return false;
        return @file($path);
    }

    // Parse quiz file: settings, popup instructions, header text, categories with their questions,
    // fenced ```mermaid / ```chartjs blocks and pasted <iframe>, <audio>, <video> code
    private function parseQuiz($lines) {
        $raw = array();
        $header = $pending = $questions = $categories = $instructions = array();
        $tfStrings = array($this->text("quizTrue"), $this->text("quizFalse"));
        $current = -1;
        $hasMermaid = $hasChart = false;
        $total = count($lines);
        for ($n=0; $n<$total; $n++) {
            $line = rtrim($lines[$n], "\r\n");
            if (trim($line)=="") continue;
            $block = null;
            if (preg_match('/^\s*```\s*([A-Za-z0-9_\-]*)\s*$/', $line, $matches)) {
                $language = strtolower($matches[1]);
                $code = array();
                for ($n++; $n<$total; $n++) {
                    $inner = rtrim($lines[$n], "\r\n");
                    if (preg_match('/^\s*```\s*$/', $inner)) break;
                    $code[] = $inner;
                }
                $kind = $language=="mermaid" ? "mermaid" : ($language=="chartjs" || $language=="chart" ? "chart" : "code");
                if ($kind=="mermaid") $hasMermaid = true;
                if ($kind=="chart") $hasChart = true;
                $block = "\x01".$kind."\x01".implode("\n", $code);
            } elseif (preg_match('/^\s*<(iframe|audio|video)\b/i', $line, $matches)) {
                $tag = strtolower($matches[1]);
                $html = $line;
                for ($extra=0; stripos($html, "</".$tag)===false && $n+1<$total && $extra<40; $extra++) $html .= "\n".rtrim($lines[++$n], "\r\n");
                $block = "\x01html\x01".$html;
            }
            if ($block===null) {
                if ($line[0]=="=") {
                    $this->parseSettingLine(substr($line, 1), $raw);
                    continue;
                }
                if ($line[0]=="!" && substr($line, 0, 2)!="![") { // popup instruction, "![" stays an image or media line
                    $text = trim(substr($line, 1));
                    if ($text!="") $instructions[] = $text;
                    continue;
                }
                if ($line[0]=="@") {
                    $name = trim(substr($line, 1));
                    if ($name=="") continue;
                    if ($current>=0) $categories[$current]["outro"] = array_merge($categories[$current]["outro"], $pending);
                    elseif (count($pending)) $header = array_merge($header, $pending);
                    $pending = array();
                    $categories[] = array("name"=>$name, "intro"=>array(), "questions"=>array(), "outro"=>array());
                    $current = count($categories)-1;
                    continue;
                }
                if (preg_match('/^(\d+\.|[-+*])\s/', $line) && strpos($line, "|")!==false) {
                    $parts = array_map("trim", explode("|", $line));
                    $question = preg_replace('/^(\d+\.|[-+*])\s+/', "", $parts[0]);
                    $options = array_slice($parts, 1);
                    $isTrueFalse = count($options)==1 && ($options[0]=="1" || $options[0]=="0");
                    $trueFirst = true;
                    if ($isTrueFalse) { // correct option is always at index 0
                        $trueFirst = $options[0]=="1";
                        $options = $trueFirst ? $tfStrings : array_reverse($tfStrings);
                    }
                    if ($current<0) { // questions before the first "@" line
                        $categories[] = array("name"=>"", "intro"=>array(), "questions"=>array(), "outro"=>array());
                        $current = count($categories)-1;
                    }
                    $questions[] = array("text"=>$question, "options"=>$options, "tf"=>$isTrueFalse,
                        "trueFirst"=>$trueFirst, "pre"=>$pending, "category"=>$current);
                    $categories[$current]["questions"][] = count($questions)-1;
                    $pending = array();
                    continue;
                }
            }
            $item = $block!==null ? $block : $line;
            if ($current<0) {
                $header[] = $item;  // text before the first question or category stays on top
            } elseif (!count($categories[$current]["questions"])) {
                $categories[$current]["intro"][] = $item; // text right after "@ Name" stays at the top of the category
            } else {
                $pending[] = $item; // text between questions travels with the next question
            }
        }
        if ($current>=0) $categories[$current]["outro"] = array_merge($categories[$current]["outro"], $pending);
        else $header = array_merge($header, $pending);
        $named = 0;
        foreach ($categories as $index=>$category) {
            if (!count($category["questions"])) continue;
            if ($category["name"]!="") $named++;
        }
        // Questions before the first "@" line form the category "General" when other categories exist
        foreach ($categories as $index=>$category) {
            if ($category["name"]=="" && $named>0) $categories[$index]["name"] = "General";
        }
        $settings = $this->getSettings($raw, count($questions));
        return array("header"=>$header, "questions"=>$questions, "categories"=>$categories, "instructions"=>$instructions,
            "hasCategories"=>$named>0, "hasMermaid"=>$hasMermaid, "hasChart"=>$hasChart, "settings"=>$settings);
    }

    // Read one "=" line. Named form: "time: 50, penalty: 1". Older positional form is still understood.
    private function parseSettingLine($text, &$raw) {
        if (strpos($text, ":")!==false) {
            foreach (explode(",", $text) as $part) {
                $pair = explode(":", $part, 2);
                if (count($pair)!=2) continue;
                $key = strtolower(trim($pair[0]));
                $value = trim($pair[1]);
                if ($key!="" && $value!="") $raw[$key] = $value;
            }
        } else { // positional form of versions up to custom.7: points, wrong, time, shuffle, single attempt, answers
            $values = array_map("trim", explode(",", $text));
            if (isset($values[1]) && $values[1]!="") $raw["penalty"] = ($values[1]=="%" || (is_numeric($values[1]) && floatval($values[1])!=0)) ? "1" : "0";
            if (isset($values[2]) && $values[2]!="" && $values[2]!="%") $raw["time"] = $values[2];
            if (isset($values[3]) && $values[3]!="") $raw["shuffle"] = $this->isOn($values[3]) ? "1" : "0";
            if (isset($values[5]) && $values[5]!="") $raw["review"] = strtolower($values[5])=="all" ? "1" : (strtolower($values[5])=="none" ? "card" : "0");
        }
    }

    // Turn raw settings into checked values, anything unknown or invalid falls back to the default
    private function getSettings($raw, $count) {
        $settings = array("time"=>$count, "penalty"=>0, "shuffle"=>1, "review"=>0, "pass"=>$this->getDefaultPass(), "minimum"=>0, "open"=>0, "close"=>0);
        if (isset($raw["time"]) && preg_match('/^\d{1,5}$/', $raw["time"])) $settings["time"] = min(intval($raw["time"]), 10080);
        foreach (array("penalty", "shuffle", "review") as $key) {
            if (isset($raw[$key]) && ($raw[$key]==="0" || $raw[$key]==="1")) $settings[$key] = intval($raw[$key]);
        }
        if (isset($raw["review"]) && strtolower($raw["review"])==="card") $settings["review"] = 2;
        foreach (array("open", "close") as $key) {
            if (isset($raw[$key])) $settings[$key] = $this->parseDateTime($raw[$key]);
        }
        foreach (array("pass", "minimum") as $key) {
            if (isset($raw[$key]) && is_numeric($raw[$key]) && floatval($raw[$key])>=0 && floatval($raw[$key])<=100) {
                $settings[$key] = round(floatval($raw[$key]), 1);
            }
        }
        return $settings;
    }

    // "2026-10-10 08:00" (also "2026-10-10" or with seconds) in the time zone of the site (CoreTimezone), 0 when invalid
    private function parseDateTime($text) {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', trim($text), $m)) return 0;
        if (!checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) return 0;
        $hour = isset($m[4]) && $m[4]!=="" ? intval($m[4]) : 0;
        $minute = isset($m[5]) && $m[5]!=="" ? intval($m[5]) : 0;
        $second = isset($m[6]) && $m[6]!=="" ? intval($m[6]) : 0;
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

    // Pass mark of the site: QuizPass, else the older QuizCertificateMinScore, else 80
    private function getDefaultPass() {
        foreach (array("quizPass", "quizCertificateMinScore") as $key) {
            $value = trim((string)$this->yellow->system->get($key));
            if ($value!="" && is_numeric($value) && floatval($value)>=0 && floatval($value)<=100) return round(floatval($value), 1);
        }
        return 80;
    }

    // The attempt is kept by the browser; quiz.js sets the same cookie, this header only saves one step
    private function sendAttemptCookie($quizId, $attempt, $minutes) {
        if (headers_sent()) return;
        $secure = isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"]!=="" && $_SERVER["HTTPS"]!=="off" ? "; Secure" : "";
        @header("Set-Cookie: yquiz_".$quizId."=".$attempt["token"]."; Path=/; Max-Age=".$this->getLifetime($minutes)."; SameSite=Lax".$secure, false);
    }

    // The pass mark that really decides: one category means its mastery is the score, so the higher number counts
    private function getRules($quiz) {
        $pass = $quiz["settings"]["pass"];
        $minimum = $quiz["settings"]["minimum"];
        if (!$quiz["hasCategories"]) { $pass = max($pass, $minimum); $minimum = 0; }
        return array($pass, $minimum);
    }

    // Start popup: title, facts from the settings (English), instructions from "!" lines, last score, reprint
    private function renderIntro($quiz, $quizId, $title) {
        $settings = $quiz["settings"];
        $count = count($quiz["questions"]);
        list($pass, $minimum) = $this->getRules($quiz);
        $facts = array(); // label => value, shown as a plain two-column list
        $facts[$this->text("quizIntroQuestionsLabel")] = (string)$count;
        $facts[$this->text("quizIntroTimeLabel")] = $settings["time"]>0 ? ($settings["time"]==1 ? $this->text("quizIntroOneMinute") :
            str_replace("@minutes", $settings["time"], $this->text("quizIntroMinutes"))) : $this->text("quizIntroNoTime");
        if ($settings["open"]>0) $facts[$this->text("quizIntroOpensLabel")] = $this->formatDateTime($settings["open"]);
        if ($settings["close"]>0) $facts[$this->text("quizIntroClosesLabel")] = $this->formatDateTime($settings["close"]);
        $facts[$this->text("quizIntroPenaltyLabel")] = $settings["penalty"]==1 ? $this->text("quizIntroPenaltyOn") : $this->text("quizIntroPenaltyOff");
        if ($quiz["hasCategories"]) {
            $names = array();
            foreach ($quiz["categories"] as $category) if (count($category["questions"])) $names[] = $category["name"];
            $facts[$this->text("quizIntroPartsLabel")] = implode(", ", $names);
        }
        if ($pass<=0 && $minimum<=0) $certificate = $this->text("quizIntroPassNone");
        elseif ($minimum>0) $certificate = str_replace(array("@pass", "@minimum"), array($this->formatNumber($pass), $this->formatNumber($minimum)), $this->text("quizIntroPassMinimum"));
        else $certificate = str_replace("@pass", $this->formatNumber($pass), $this->text("quizIntroPass"));
        $facts[$this->text("quizIntroCertificateLabel")] = $certificate;
        $texts = array("last"=>$this->text("quizIntroLast"), "viewLast"=>$this->text("quizIntroViewLast"), "preparing"=>$this->text("quizPreparing"),
            "forgetConfirm"=>$this->text("quizForgetConfirm"), "forgetRemove"=>$this->text("quizForgetRemove"),
            "forgetCancel"=>$this->text("quizForgetCancel"), "site"=>(string)$this->yellow->system->get("sitename"));
        $page = $this->yellow->page;
        $home = method_exists($page, "getHomeLocation") ? $page->getHomeLocation(true) : $page->base."/";
        $start = $this->getPagePath()."?quiz_start=1";
        $state = $this->getScheduleState($settings);
        $output = "<div lang=\"en\" class=\"quiz-container quiz-intro quiz-protect\" id=\"quiz-{$quizId}\" data-quiz-id=\"{$quizId}\"";
        $output .= " data-i18n=\"".$this->e($this->toJson($texts))."\">\n";
        $output .= "<div class=\"quiz-intro-layer\">\n<div class=\"quiz-intro-card\" role=\"dialog\" aria-modal=\"true\" aria-labelledby=\"quiz-{$quizId}-title\">\n";
        $output .= "<div class=\"quiz-intro-main\">\n";
        // only div and span with quiz class names: themes often style or script h2, dl/dt/dd (accordions), ul/li
        $output .= "<div class=\"quiz-intro-title\" role=\"heading\" aria-level=\"2\" id=\"quiz-{$quizId}-title\">".$this->e($title)."</div>\n";
        $output .= "<div class=\"quiz-intro-facts\">";
        foreach ($facts as $label=>$value) {
            $output .= "<div class=\"quiz-intro-fact\"><span class=\"quiz-intro-label\">".$this->e($label)."</span><span class=\"quiz-intro-value\">".$this->e($value)."</span></div>";
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
        $output .= "<div class=\"quiz-intro-actions\">";
        if ($state=="open") $output .= "<a class=\"quiz-btn quiz-start\" href=\"".$this->e($start)."\">".$this->e($this->text("quizIntroStart"))."</a>";
        $output .= "<a class=\"quiz-btn quiz-btn-quiet quiz-notnow\" href=\"".$this->e($home)."\">".$this->e($this->text("quizIntroNotNow"))."</a></div>\n";
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

    // Check a signed result of this quiz, returns data, or null when it is forged, broken or from another quiz
    private function parseResult($quizId, $token) {
        if (!is_string($token) || strlen($token)>3000) return null;
        if (!preg_match('/^([a-f0-9]{16})\.(\d{9,11})\.(\d{9,11})\.(\d{1,2})\.([0-2])\.((?:[a-f0-9]{8}|-{8})*)\.([a-f0-9]{32})$/', $token, $matches)) return null;
        $payload = substr($token, 0, strrpos($token, "."));
        if (!hash_equals(substr($this->sign("result|".$quizId."|".$payload), 0, 32), $matches[7])) return null;
        $answers = array();
        foreach ($matches[6]!=="" ? str_split($matches[6], 8) : array() as $value) $answers[] = $value==="--------" ? "" : $value;
        return array("q"=>$quizId, "s"=>$matches[1], "t"=>intval($matches[2]), "u"=>intval($matches[3]),
            "w"=>intval($matches[4]), "x"=>intval($matches[5]), "a"=>$answers);
    }

    // Display order: categories keep their order, questions are shuffled inside their category
    private function getOrder($quiz, $seed) {
        $order = array();
        foreach ($quiz["categories"] as $index=>$category) {
            if (!count($category["questions"])) continue;
            $list = $category["questions"];
            if ($quiz["settings"]["shuffle"]) $list = $this->shuffleSeeded($list, $seed, "q".$index);
            $order[$index] = $list;
        }
        return $order;
    }

    // Render quiz form
    private function renderForm($quiz, $quizId, $attempt) {
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
        $output .= " data-time=\"{$minutes}\" data-remaining=\"{$remaining}\" data-lifetime=\"".$this->getLifetime($minutes)."\"".$schedule.$this->getLibraryAttributes($quiz);
        $output .= " data-i18n=\"".$this->e($this->toJson($this->getClientTexts()))."\">\n";
        $output .= "<div class=\"quiz-notice quiz-locked quiz-js-notice\">".$this->e($this->text("quizJsRequired"))."</div>\n";
        $output .= "<form class=\"quiz-form\" id=\"quiz-form\" method=\"post\" action=\"".$this->e($this->getPagePath())."\">\n";
        $output .= "<input type=\"hidden\" name=\"quiz_id\" value=\"{$quizId}\" />\n";
        $output .= "<input type=\"hidden\" name=\"quiz_attempt\" value=\"".$this->e($attempt["token"])."\" />\n";
        $output .= "<input type=\"hidden\" name=\"quiz_client\" value=\"\" />\n";
        $output .= "<input type=\"hidden\" name=\"quiz_away\" value=\"0\" />\n";
        $output .= "<input type=\"hidden\" name=\"quiz_auto\" value=\"\" />\n";
        foreach ($quiz["header"] as $line) $output .= $this->renderLine($line)."\n";
        $number = 0;
        foreach ($order as $categoryIndex=>$list) {
            $category = $quiz["categories"][$categoryIndex];
            $output .= "<div class=\"quiz-section\">\n";
            if ($quiz["hasCategories"]) $output .= "<h2 class=\"quiz-category\">".$this->e($category["name"])."</h2>\n";
            foreach ($category["intro"] as $line) $output .= $this->renderLine($line)."\n";
            foreach ($list as $index) {
                $question = $quiz["questions"][$index];
                $number++;
                foreach ($question["pre"] as $line) $output .= $this->renderLine($line)."\n";
                $labelId = "quiz-{$quizId}-q{$index}";
                $output .= "<div class=\"quiz-q\" role=\"radiogroup\" aria-labelledby=\"{$labelId}\">\n";
                $output .= $this->renderQuestionText($question, $number, $labelId);
                $output .= "<div class=\"quiz-options\">\n";
                foreach ($this->getOptionOrder($question, $index, $attempt["seed"]) as $option) {
                    $token = $this->getToken($quizId, $attempt["seed"], $index, $option);
                    $output .= "<label class=\"quiz-option\"><input type=\"radio\" name=\"quest[{$index}]\" value=\"{$token}\" />";
                    $output .= "<span class=\"quiz-option-text\">".$this->toHTML($question["options"][$option], false)."</span></label>\n";
                }
                $output .= "</div>\n</div>\n";
            }
            foreach ($category["outro"] as $line) $output .= $this->renderLine($line)."\n";
            $output .= "</div>\n";
        }
        $output .= "<div class=\"quiz-actions\"><button type=\"submit\" class=\"quiz-btn quiz-submit\">".$this->e($this->text("quizButton"))."</button></div>\n";
        $output .= "</form>\n";
        if ($minutes>0) {
            $output .= "<div class=\"quiz-progress\" id=\"quiz-progress\" role=\"timer\" aria-live=\"off\">";
            $output .= "<svg class=\"quiz-clock\" viewBox=\"0 0 24 24\" aria-hidden=\"true\"><circle cx=\"12\" cy=\"13\" r=\"8\"/><path d=\"M12 9v4l2.5 2.5M9 2.5h6\"/></svg>";
            $output .= "<span class=\"quiz-timer-main\"><span class=\"quiz-timer-label\">".$this->e($this->text("quizTimeLeft"))."</span>";
            $output .= "<span class=\"quiz-progresstext\" id=\"quiz-progresstext\">".floor($remaining/60).":".sprintf("%02d", $remaining%60)."</span></span>";
            $output .= "<span class=\"quiz-answered\"><b class=\"quiz-answered-count\">0/{$count}</b> ".$this->e($this->text("quizAnsweredLabel"))."</span>";
            $output .= "<span class=\"quiz-progressbar\" id=\"quiz-progressbar\"></span></div>\n";
        }
        $output .= "</div>\n";
        return $output;
    }

    // Grade the stored answers: every question counts 1, a wrong answer with penalty counts -1/(options-1)
    private function gradeResult($quiz, $quizId, $data) {
        $seed = (string)$data["s"];
        $penaltyOn = $quiz["settings"]["penalty"]==1;
        $items = array();
        $right = $wrong = 0;
        foreach ($quiz["questions"] as $index=>$question) {
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

    // Render result: score, mastery per category, certificate state and answer review
    private function renderResult($quiz, $quizId, $recordKey, $data, $title, $resultToken) {
        $settings = $quiz["settings"];
        $minutes = $settings["time"];
        $start = intval($data["t"]);
        $submitted = intval($data["u"]);
        if ($submitted<$start || $submitted>$start+$this->getLifetime($minutes)) return $this->renderInvalid($quizId);
        $seed = (string)$data["s"];
        $grade = $this->gradeResult($quiz, $quizId, $data);
        $items = $grade["items"];
        $count = count($quiz["questions"]);
        $totalUnits = 0;
        $penaltyUnits = 0;
        $categoryStats = array();
        foreach ($items as $item) {
            $totalUnits += $item["units"];
            if ($item["status"]=="wrong") $penaltyUnits += $item["penalty"];
            $c = $item["category"];
            if (!isset($categoryStats[$c])) $categoryStats[$c] = array("units"=>0, "count"=>0);
            $categoryStats[$c]["units"] += $item["units"];
            $categoryStats[$c]["count"]++;
        }
        $score = $this->getPercent($totalUnits, $count);
        $parts = array();
        foreach ($quiz["categories"] as $index=>$category) {
            if (!isset($categoryStats[$index])) continue;
            $parts[] = array($category["name"], $this->getPercent($categoryStats[$index]["units"], $categoryStats[$index]["count"]), $index);
        }
        list($pass, $minimum) = $this->getRules($quiz);
        $partsOk = true;
        $below = array();
        foreach ($parts as $part) {
            if ($minimum>0 && $part[1]<$minimum) { $partsOk = false; $below[] = $part; }
        }
        $meets = $score>=$pass && $partsOk;
        $deadline = $this->getDeadline($settings, $start);
        $isLate = $deadline>0 && $submitted>$deadline+self::GRACE;
        $nonce = substr($this->sign("nonce|".$quizId."|".$seed."|".$start), 0, 20);
        $certState = "none";
        $issuedTo = "";
        if (!$isLate && $meets) {
            $record = $this->findRecord($recordKey, "attempt", $nonce);
            if ($record) {
                $certState = "used";
                $issuedTo = $record["name"];
            } else {
                $certState = time()>$submitted+self::CERT_WINDOW ? "expired" : "open";
            }
        }
        $certParts = array();
        if ($quiz["hasCategories"]) foreach ($parts as $part) $certParts[] = array($part[0], $part[1]);
        $payload = $certState=="open" ? $this->toJson(array("id"=>$quizId, "file"=>$recordKey, "nonce"=>$nonce, "title"=>$title,
            "score"=>$score, "pct"=>$score, "parts"=>$certParts, "sub"=>$submitted, "min"=>round(($submitted-$start)/60, 1))) : "";
        $resultData = array("id"=>$quizId, "title"=>$title, "score"=>$score, "pct"=>$score, "parts"=>$certParts,
            "nonce"=>$nonce, "cert"=>$certState, "late"=>$isLate ? 1 : 0, "away"=>$data["w"], "auto"=>$data["x"],
            "payload"=>$payload, "sig"=>$payload!="" ? $this->sign("cert|".$payload) : "");
        $output = "<div lang=\"en\" class=\"quiz-container quiz-is-result quiz-protect\" id=\"quiz-{$quizId}\" data-quiz-id=\"{$quizId}\"";
        if ($resultToken!="") $output .= " data-result-token=\"".$this->e($resultToken)."\"";
        $output .= $this->getLibraryAttributes($quiz);
        $output .= " data-result=\"".$this->e($this->toJson($resultData))."\" data-i18n=\"".$this->e($this->toJson($this->getClientTexts()))."\">\n";
        // summary card
        $output .= "<div class=\"quiz-summary\">";
        $output .= "<div class=\"quiz-stats\" aria-hidden=\"true\"><div class=\"quiz-score\"><span class=\"quiz-score-value\">".$this->formatNumber($score)."</span>";
        $output .= "<span class=\"quiz-score-max\">/ 100</span></div></div>";
        $output .= "<div class=\"quiz-summary-text\">";
        $output .= "<p>".str_replace(array("@right_answers", "@curr_question"), array($grade["right"], $count), $this->text("quizResult"))."</p>";
        $output .= "<p>".str_replace(array("@score", "@max_score"), array($this->formatNumber($score), "100"), $this->text("quizScore"))."</p>";
        if ($quiz["hasCategories"]) {
            $output .= "<div class=\"quiz-parts\">";
            foreach ($parts as $part) {
                $mark = $minimum>0 ? ($part[1]>=$minimum ? " <span class=\"quiz-part-ok\">✓</span>" : " <span class=\"quiz-part-low\">✕</span>") : "";
                $output .= "<span class=\"quiz-part\"><span class=\"quiz-part-name\">".$this->e($part[0])."</span> <span class=\"quiz-part-value\">".$this->formatNumber($part[1])."%</span>{$mark}</span>";
            }
            $output .= "</div>";
            $note = $this->text("quizCategoryNote");
            if ($minimum>0) $note .= " ".str_replace("@minimum", $this->formatNumber($minimum), $this->text("quizCategoryMinimum"));
            $output .= "<p class=\"quiz-mastery\">".$this->e($note)."</p>";
        } else {
            $output .= "<p class=\"quiz-mastery\">".str_replace("@percent", $this->formatNumber($score), $this->text("quizMastery"))."</p>";
        }
        if ($settings["penalty"]==1) {
            $output .= "<p class=\"quiz-info\">".$this->e(str_replace(array("@points", "@count"),
                array($this->formatNumber(round($penaltyUnits*100/$count, 1)), $grade["wrong"]), $this->text("quizPenaltyInfo")))."</p>";
        }
        if ($data["x"]==2) $output .= "<p class=\"quiz-info quiz-info-strong\">".$this->e($this->text("quizAwaySubmitted"))."</p>";
        elseif ($data["w"]>0) $output .= "<p class=\"quiz-info\">".$this->e(str_replace("@count", $data["w"], $this->text("quizAwayCount")))."</p>";
        $output .= "<div class=\"quiz-summary-actions\">";
        if ($certState=="open") {
            $output .= "<button type=\"button\" class=\"quiz-btn quiz-cert-open\">".$this->e($this->text("quizCertButton"))."</button>";
        } elseif ($certState=="used") {
            $output .= "<button type=\"button\" class=\"quiz-btn quiz-cert-open is-done\" disabled=\"disabled\">".
                $this->e(str_replace("@name", $issuedTo, $this->text("quizCertIssued")))."</button>";
        }
        $output .= "<a class=\"quiz-btn quiz-btn-quiet quiz-retake\" href=\"".$this->e($this->getRetakeUrl($quizId))."\">".$this->e($this->text("quizRetake"))."</a>";
        $output .= "</div>";
        if (!$meets) $output .= "<p class=\"quiz-cert-hint\">".$this->e($this->getCertificateHint($quiz, $items, $parts, $score, $pass, $minimum, $below))."</p>";
        if ($certState=="expired") $output .= "<p class=\"quiz-cert-hint\">".$this->e($this->text("quizCertExpired"))."</p>";
        $output .= "</div></div>\n";
        $output .= "<p class=\"quiz-forget-line\"><button type=\"button\" class=\"quiz-forget\">".$this->e($this->text("quizForget"))."</button></p>\n";
        if ($isLate) $output .= "<div class=\"quiz-notice quiz-locked\">".$this->e($this->text("quizLate"))."</div>\n";
        // answer review (not shown with "review: card")
        if ($settings["review"]!=2) {
        $showKey = $settings["review"]==1;
        $output .= "<div class=\"quiz-notice\" id=\"quiz-correction\">".($showKey ? $this->text("quizCorrected") : $this->e($this->text("quizCorrectedMarks")))."</div>\n";
        foreach ($quiz["header"] as $line) $output .= $this->renderLine($line)."\n";
        $number = 0;
        foreach ($this->getOrder($quiz, $seed) as $categoryIndex=>$list) {
            $category = $quiz["categories"][$categoryIndex];
            $output .= "<div class=\"quiz-section\">\n";
            if ($quiz["hasCategories"]) $output .= "<h2 class=\"quiz-category\">".$this->e($category["name"])."</h2>\n";
            foreach ($category["intro"] as $line) $output .= $this->renderLine($line)."\n";
            foreach ($list as $index) {
                $question = $quiz["questions"][$index];
                $item = $items[$index];
                $number++;
                foreach ($question["pre"] as $line) $output .= $this->renderLine($line)."\n";
                $output .= "<div class=\"quiz-q quiz-corrected q-".$item["status"]."\">\n";
                $output .= $this->renderQuestionText($question, $number, "");
                $output .= "<div class=\"quiz-options\">\n";
                foreach ($this->getOptionOrder($question, $index, $seed) as $option) {
                    $html = $this->toHTML($question["options"][$option], false);
                    if ($option==0 && ($showKey || $item["chosen"]==0)) {
                        $output .= "<div class=\"quiz-option is-correct".($item["chosen"]==0 ? " is-chosen" : "")."\"><span class=\"quiz-option-text\"><b>{$html}</b></span></div>\n";
                    } elseif ($option==$item["chosen"]) {
                        $output .= "<div class=\"quiz-option is-wrong is-chosen\"><span class=\"quiz-option-text\"><del class=\"quiz-error\">{$html}</del></span></div>\n";
                    } else {
                        $output .= "<div class=\"quiz-option\"><span class=\"quiz-option-text\">{$html}</span></div>\n";
                    }
                }
                $output .= "</div>\n";
                if ($item["status"]=="skipped") $output .= "<p class=\"quiz-q-note\">".$this->e($this->text("quizNotAnswered"))."</p>\n";
                $output .= "</div>\n";
            }
            foreach ($category["outro"] as $line) $output .= $this->renderLine($line)."\n";
            $output .= "</div>\n";
        }
        }
        $output .= "</div>\n";
        return $output;
    }

    // Explain what is missing for the certificate: fewest extra correct answers, pass mark, parts below the minimum
    private function getCertificateHint($quiz, $items, $parts, $score, $pass, $minimum, $below) {
        $count = count($items);
        $categoryUnits = $categoryCount = $gains = array();
        $totalUnits = 0;
        foreach ($items as $item) {
            $c = $item["category"];
            if (!isset($categoryUnits[$c])) { $categoryUnits[$c] = 0; $categoryCount[$c] = 0; $gains[$c] = array(); }
            $categoryUnits[$c] += $item["units"];
            $categoryCount[$c]++;
            $totalUnits += $item["units"];
            if ($item["gain"]>0) $gains[$c][] = $item["gain"];
        }
        foreach ($gains as $c=>$list) rsort($gains[$c]);
        $needed = 0;
        if ($minimum>0) { // first lift every part to the minimum, using its own questions
            foreach ($categoryUnits as $c=>$units) {
                while ($this->getPercent($categoryUnits[$c], $categoryCount[$c])<$minimum && count($gains[$c])) {
                    $gain = array_shift($gains[$c]);
                    $categoryUnits[$c] += $gain;
                    $totalUnits += $gain;
                    $needed++;
                }
            }
        }
        while ($this->getPercent($totalUnits, $count)<$pass) { // then reach the pass mark with the best remaining answers
            $best = null;
            foreach ($gains as $c=>$list) {
                if (count($list) && ($best===null || $list[0]>$gains[$best][0])) $best = $c;
            }
            if ($best===null) break;
            $totalUnits += array_shift($gains[$best]);
            $needed++;
        }
        $text = $needed==1 ? $this->text("quizCertNeedOne") : str_replace("@count", (string)$needed, $this->text("quizCertNeed"));
        if ($score<$pass) $text .= " ".str_replace("@min", $this->formatNumber($pass), $this->text("quizCertNeedPass"));
        if (count($below)) {
            $list = array();
            foreach ($below as $part) $list[] = $part[0]." (".$this->formatNumber($part[1])."%)";
            $text .= " ".str_replace(array("@minimum", "@list"), array($this->formatNumber($minimum), implode(", ", $list)), $this->text("quizCertNeedParts"));
        }
        return $text;
    }

    // Reprint form: on its own page ([quizcertificate]) with a title, or inside the start popup without one
    private function renderReprint($inline) {
        static $counter = 0;
        $counter++;
        $text = function($key) { return $this->text($key); };
        $days = $this->getKeepDays();
        $keep = $days>0 ? str_replace("@days", $days, $text("quizReprintKeep")) : $text("quizReprintKeepForever");
        $texts = $this->getClientTexts();
        foreach (array("reprintDone"=>"quizReprintDone", "reprintNotFound"=>"quizReprintNotFound", "reprintInvalid"=>"quizReprintInvalid") as $name=>$key) $texts[$name] = $text($key);
        $id = "quiz-reprint-number-".$counter;
        $form = "<div class=\"quiz-reprint\" data-i18n=\"".$this->e($this->toJson($texts))."\">\n<div class=\"quiz-reprint-card\">\n";
        if (!$inline) $form .= "<p class=\"quiz-reprint-title\">".$this->e($text("quizReprintTitle"))."</p>\n";
        $form .= "<p class=\"quiz-reprint-text\">".$this->e($text("quizReprintPrompt"))." ".$this->e($keep)."</p>\n";
        $form .= "<form class=\"quiz-reprint-form\" method=\"post\" novalidate>\n";
        $form .= "<label class=\"quiz-reprint-label\" for=\"{$id}\">".$this->e($text("quizReprintLabel"))."</label>\n";
        $form .= "<div class=\"quiz-reprint-row\"><input type=\"text\" id=\"{$id}\" name=\"number\" maxlength=\"20\" autocomplete=\"off\" spellcheck=\"false\" placeholder=\"".$this->e($text("quizReprintPlaceholder"))."\" />";
        $form .= "<button type=\"submit\" class=\"quiz-btn\">".$this->e($text("quizReprintButton"))."</button></div>\n";
        $form .= "</form>\n<p class=\"quiz-reprint-message\" role=\"status\" hidden></p>\n</div>\n</div>\n";
        return $form;
    }

    // [quizleaderboard file.txt] or [quizleaderboard file.txt 10]: certificates of one quiz, best score per full name.
    // Only rank, full name, score and date are shown: no certificate numbers, device codes or other codes.
    private function renderLeaderboard($text) {
        $arguments = preg_split('/\s+/', trim($text));
        $fileName = isset($arguments[0]) ? $arguments[0] : "";
        $limit = isset($arguments[1]) && ctype_digit($arguments[1]) ? intval($arguments[1]) : 0;
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-.\/]*$/', $fileName) || strpos($fileName, "..")!==false) return null;
        $prefix = substr($this->getRecordKey($fileName, "000000000000"), 0, -6);
        $directory = $this->getDataDirectory(false);
        $best = array();
        if ($directory!==false) {
            foreach ((array)glob($directory.$prefix."[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f].csv") as $file) {
                foreach ($this->readRecords($file) as $record) {
                    $key = function_exists("mb_strtolower") ? mb_strtolower($record["name"], "UTF-8") : strtolower($record["name"]);
                    $key = preg_replace('/\s+/u', " ", trim($key));
                    $score = floatval($record["score"]);
                    if (!isset($best[$key]) || $score>$best[$key][1] || ($score==$best[$key][1] && $record["issued"]<$best[$key][2])) {
                        $best[$key] = array($record["name"], $score, $record["issued"]);
                    }
                }
            }
        }
        usort($best, function($a, $b) { return $a[1]==$b[1] ? strcmp($a[2], $b[2]) : ($a[1]<$b[1] ? 1 : -1); });
        if ($limit>0) $best = array_slice($best, 0, $limit);
        $output = "<div lang=\"en\" class=\"quiz-container quiz-board\" data-i18n=\"".$this->e($this->toJson(array("site"=>(string)$this->yellow->system->get("sitename"))))."\">\n";
        if (!count($best)) return $output."<p class=\"quiz-board-empty\">".$this->e($this->text("quizBoardEmpty"))."</p>\n</div>\n";
        $output .= "<div class=\"quiz-board-row quiz-board-head\"><span>".$this->e($this->text("quizBoardRank"))."</span><span>".$this->e($this->text("quizBoardName")).
            "</span><span>".$this->e($this->text("quizBoardScore"))."</span><span>".$this->e($this->text("quizBoardDate"))."</span></div>\n";
        foreach ($best as $index=>$row) {
            $output .= "<div class=\"quiz-board-row\"><span>".($index+1)."</span><span>".$this->e($row[0])."</span><span>".$this->formatNumber($row[1]).
                "</span><span>".$this->e(substr($row[2], 0, 10))."</span></div>\n";
        }
        return $output."</div>\n";
    }

    // Render notice for a forged, broken or expired attempt or result, nothing is graded
    private function renderInvalid($quizId) {
        $output = "<div lang=\"en\" class=\"quiz-container\" id=\"quiz-{$quizId}\">\n";
        $output .= "<div class=\"quiz-notice quiz-locked\">".$this->e($this->text("quizInvalid"))."</div>\n";
        $output .= "<a class=\"quiz-btn quiz-btn-quiet quiz-retake\" href=\"".$this->e($this->getRetakeUrl($quizId))."\">".$this->e($this->text("quizRetake"))."</a>\n";
        $output .= "</div>\n";
        return $output;
    }

    private function renderNotice($quizId, $text) {
        $id = $quizId!="" ? " id=\"quiz-{$quizId}\"" : "";
        return "<div lang=\"en\" class=\"quiz-container\"{$id}><div class=\"quiz-notice quiz-locked\">".$this->e($text)."</div></div>\n";
    }

    // Path of the current page without scheme and host, so links work whatever host name the server reports
    private function getPagePath() {
        $page = $this->yellow->page;
        return method_exists($page, "getLocation") ? $page->getLocation(true) : $page->base.$page->location;
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

    // Shuffle array with a seed, same seed gives same order
    private function shuffleSeeded($items, $seed, $salt) {
        $items = array_values($items);
        for ($i=count($items)-1; $i>0; $i--) {
            $j = hexdec(substr(md5($seed."|".$salt."|".$i), 0, 7)) % ($i+1);
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

    // Numbers are shown with at most one decimal; pass mark and minimum are compared with these shown numbers
    private function formatNumber($number) {
        $text = (string)round((float)$number, 1);
        return $text=="-0" ? "0" : $text;
    }

    // Issue a certificate once per attempt, the same name may download it again
    private function issueCertificate($input) {
        if ($this->certResponse!==null) return $this->certResponse;
        $payload = isset($input["cert_payload"]) && is_string($input["cert_payload"]) ? $input["cert_payload"] : "";
        $signature = isset($input["cert_sig"]) && is_string($input["cert_sig"]) ? $input["cert_sig"] : "";
        $name = $this->cleanName(isset($input["cert_name"]) && is_string($input["cert_name"]) ? $input["cert_name"] : "");
        $data = json_decode($payload, true);
        if ($this->getSecret()=="" || $payload=="" || !hash_equals($this->sign("cert|".$payload), $signature) || !is_array($data) ||
            !isset($data["id"], $data["file"], $data["nonce"], $data["title"], $data["score"], $data["pct"], $data["sub"], $data["parts"], $data["min"]) || !is_array($data["parts"]) || !is_numeric($data["min"]) ||
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
        $this->certResponse = $response;
        return $response;
    }

    // Find certificate by number for reprinting, expired or deleted records are not found
    private function reprintCertificate($input) {
        $number = isset($input["number"]) && is_string($input["number"]) ? strtoupper(preg_replace('/[\s\-]+/', "", $input["number"])) : "";
        if (!preg_match('/^[0-9A-F]{10}$/', $number) || $this->getSecret()=="") return array("ok"=>false, "error"=>"invalid");
        $directory = $this->getDataDirectory(false);
        $files = $directory!==false ? (array)glob($directory."*.csv") : array();
        $legacy = $this->getLegacyFile();
        if (is_file($legacy)) $files[] = $legacy;
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
        $best = $existing ? null : $this->searchFiles(array($file), "identity", $identity);
        if ($existing) {
            $response = $existing["name"]===$name ? $this->getCertificateData($existing) :
                array("ok"=>false, "error"=>"used", "issuedTo"=>$existing["name"]);
        } elseif ($best && floatval($best["score"])>=floatval($data["score"])) {
            $response = $this->getCertificateData($best); // a lower or equal score keeps the better certificate
            $response["kept"] = true;
        } elseif (is_file($file) && filesize($file)>=self::FILE_MAX) {
            $response = array("ok"=>false, "error"=>"storage");
        } else {
            $record = array("issued"=>date("Y-m-d H:i:s"), "certificate_no"=>$this->getUniqueNumber($directory, $data["nonce"], $name),
                "name"=>$name, "device"=>$device==="none" ? "" : substr($this->sign("device|".$device), 0, 6), "minutes"=>$this->formatNumber($data["min"]),
                "score"=>$this->formatNumber($data["score"]), "percent"=>$this->formatNumber($data["pct"]),
                "quiz_title"=>(string)$data["title"], "attempt"=>(string)$data["nonce"], "parts"=>$this->encodeParts($data["parts"]),
                "identity"=>$identity);
            if (is_file($file) && filesize($file)>0 && !$this->hasCurrentHeader($file)) {
                $ok = $this->migrateRecords($file, $best, $record); // file of an older version: rewrite it once with all columns
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
        $name = function_exists("mb_strtolower") ? mb_strtolower($name, "UTF-8") : strtolower($name);
        return substr($this->sign("identity|".preg_replace('/\s+/u', " ", trim($name))."|".$device), 0, 16);
    }

    private function hasCurrentHeader($file) {
        $handle = @fopen($file, "r");
        if (!$handle) return false;
        $line = fgets($handle);
        fclose($handle);
        return is_string($line) && rtrim($line, "\r\n")===rtrim($this->toCsvLine($this->getRecordColumns()), "\n");
    }

    // Replace one line (found by its certificate number) and add the new record, the file is written in one step
    private function replaceRecord($file, $number, $record) {
        $content = @file_get_contents($file);
        if ($content===false) return false;
        $end = strpos($content, "\n");
        $header = str_getcsv($end===false ? $content : substr($content, 0, $end), ",", "\"", "\\");
        $offset = 0;
        while (($position = strpos($content, $number, $offset))!==false) {
            $offset = $position+1;
            $lineStart = strrpos(substr($content, 0, $position), "\n");
            if ($lineStart===false) continue;
            $lineEnd = strpos($content, "\n", $position);
            $line = substr($content, $lineStart+1, ($lineEnd===false ? strlen($content) : $lineEnd)-$lineStart-1);
            $old = $this->toRecord($header, str_getcsv($line, ",", "\"", "\\"));
            if ($old && $old["certificate_no"]===$number) {
                $content = substr($content, 0, $lineStart+1).($lineEnd===false ? "" : substr($content, $lineEnd+1));
                break;
            }
        }
        if ($content!=="" && substr($content, -1)!=="\n") $content .= "\n";
        $content .= $this->toCsvLine($this->getRecordRow($record));
        return $this->writeContent($file, $content);
    }

    private function migrateRecords($file, $best, $record) {
        $records = array();
        foreach ($this->readRecords($file) as $old) {
            if (!$best || $old["certificate_no"]!==$best["certificate_no"]) $records[] = $old;
        }
        $records[] = $record;
        return $this->writeRecords($file, $records);
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
        $legacy = $this->getLegacyFile();
        if (is_file($legacy)) $files[] = $legacy;
        for ($round=0; $round<10; $round++) {
            $number = strtoupper(substr($this->sign("code|".$nonce."|".$name.($round ? "|".$round : "")), 0, 10));
            if (!$this->searchFiles($files, "certificate_no", $number)) break;
        }
        return $number;
    }

    // Append one record, the file is cut back to its old size if writing fails (for example a full disk)
    private function appendRecord($file, $record) {
        $handle = @fopen($file, "c");
        if (!$handle) return false;
        fseek($handle, 0, SEEK_END);
        $size = ftell($handle);
        $text = ($size==0 ? $this->toCsvLine($this->getRecordColumns()) : "").$this->toCsvLine($this->getRecordRow($record));
        $written = fwrite($handle, $text);
        $ok = $written===strlen($text) && fflush($handle);
        if (!$ok) ftruncate($handle, $size);
        fclose($handle);
        return $ok;
    }

    private function getRecordColumns() {
        return array("issued", "certificate_no", "name", "device", "minutes", "score", "percent", "quiz_title", "attempt", "parts", "identity");
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
    private function getRecordRow($record) {
        $row = array();
        foreach ($this->getRecordColumns() as $column) {
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
            "score"=>is_numeric($record["score"]) ? floatval($record["score"]) : $record["score"],
            "pct"=>is_numeric($record["percent"]) ? floatval($record["percent"]) : null, "parts"=>$this->decodeParts($record["parts"]));
    }

    // Find a stored certificate of a quiz by column value, also looks in the registry of older versions
    private function findRecord($recordKey, $column, $value) {
        $directory = $this->getDataDirectory(false);
        $files = array();
        if ($directory!==false) $files[] = $directory.$recordKey.".csv";
        $legacy = $this->getLegacyFile();
        if (is_file($legacy)) $files[] = $legacy;
        return $this->searchFiles($files, $column, $value);
    }

    // Fast search: look for the value as text, then parse only the lines that contain it
    private function searchFiles($files, $column, $value) {
        if ($value=="") return null;
        foreach ($files as $file) {
            if (!is_file($file)) continue;
            $content = @file_get_contents($file);
            if ($content===false || $content=="") continue;
            $end = strpos($content, "\n");
            $header = str_getcsv($end===false ? $content : substr($content, 0, $end), ",", "\"", "\\");
            $offset = 0;
            while (($position = strpos($content, $value, $offset))!==false) {
                $offset = $position+1;
                $lineStart = strrpos(substr($content, 0, $position), "\n");
                if ($lineStart===false) continue; // value found in the header line
                $lineEnd = strpos($content, "\n", $position);
                $line = substr($content, $lineStart+1, ($lineEnd===false ? strlen($content) : $lineEnd)-$lineStart-1);
                $record = $this->toRecord($header, str_getcsv($line, ",", "\"", "\\"));
                if ($record && $record[$column]===$value && !$this->isExpired($record["issued"])) return $record;
            }
        }
        return null;
    }

    // Map a CSV row to a record using the header, returns null for rows without the required columns
    private function toRecord($header, $row) {
        $record = array();
        $header = array_map("trim", $header);
        foreach ($this->getRecordColumns() as $column) {
            $index = array_search($column, $header, true);
            $record[$column] = $index!==false && isset($row[$index]) ? trim((string)$row[$index]) : "";
        }
        if ($record["issued"]=="" || $record["attempt"]=="" || $record["certificate_no"]=="") return null;
        $record["name"] = $this->displayName($record["name"]);
        $record["quiz_title"] = $this->displayName($record["quiz_title"]);
        return $record;
    }

    // Read records that are not expired, columns are matched by the header line
    private function readRecords($file) {
        $records = array();
        if (!is_file($file)) return $records;
        $handle = @fopen($file, "r");
        if (!$handle) return $records;
        $header = fgetcsv($handle, 0, ",", "\"", "\\");
        if (is_array($header)) {
            while (($row = fgetcsv($handle, 0, ",", "\"", "\\"))!==false) {
                $record = $this->toRecord($header, $row);
                if ($record && !$this->isExpired($record["issued"])) $records[] = $record;
            }
        }
        fclose($handle);
        return $records;
    }

    // Write records through a temporary file, so readers never see a half written file
    private function writeRecords($file, $records) {
        if (!count($records)) return !is_file($file) || @unlink($file);
        $content = $this->toCsvLine($this->getRecordColumns());
        foreach ($records as $record) $content .= $this->toCsvLine($this->getRecordRow($record));
        return $this->writeContent($file, $content);
    }

    // Number of days certificate records are kept, 0 = forever
    private function getKeepDays() {
        $value = trim((string)$this->yellow->system->get("quizCertificateKeepDays"));
        if ($value=="" || !is_numeric($value) || floatval($value)<0) return 30;
        return floatval($value)==0 ? 0 : max(1, (int)ceil(floatval($value)));
    }

    private function isExpired($issued) {
        $days = $this->getKeepDays();
        if ($days==0) return false;
        $time = strtotime($issued);
        return $time===false || $time+$days*86400<time();
    }

    // Remove expired certificate records and data of older versions, at most once per SWEEP_INTERVAL
    private function sweepRecords() {
        $legacy = $this->getLegacyFile();
        $directory = $this->getDataDirectory(is_file($legacy));
        if ($directory===false) return;
        $marker = $directory."index.html";
        if (is_file($marker) && time()-filemtime($marker)<self::SWEEP_INTERVAL) return;
        $lock = $this->lockData($directory, false);
        if (!$lock) return;
        $files = (array)glob($directory."*.csv");
        if (is_file($legacy)) $files[] = $legacy;
        foreach ($files as $file) {
            $records = $this->readRecords($file);
            if (count($records)!=$this->countLines($file)) $this->writeRecords($file, $records);
        }
        foreach ((array)glob($directory."*.tmp-*") as $file) {
            if (time()-filemtime($file)>3600) @unlink($file);
        }
        $this->removeOldAttempts($directory."attempts");
        @touch($marker);
        $this->unlockData($lock);
    }

    // Number of data rows in a record file
    private function countLines($file) {
        $handle = @fopen($file, "r");
        if (!$handle) return 0;
        $count = -1;
        while (fgetcsv($handle, 0, ",", "\"", "\\")!==false) $count++;
        fclose($handle);
        return max(0, $count);
    }

    // Delete submission records of version custom.6, in small portions
    private function removeOldAttempts($path) {
        if (!is_dir($path)) return;
        $removed = 0;
        foreach ((array)@scandir($path) as $day) {
            if (!preg_match('/^\d{8}$/', $day)) continue;
            foreach ((array)@scandir($path."/".$day) as $file) {
                if ($file=="." || $file=="..") continue;
                @unlink($path."/".$day."/".$file);
                if (++$removed>=2000) return;
            }
            @rmdir($path."/".$day);
        }
        @rmdir($path);
    }

    // Data directory for certificate records, closed to web access
    private function getDataDirectory($create) {
        $directory = trim((string)$this->yellow->system->get("quizDataDirectory"));
        if ($directory=="") $directory = dirname(__FILE__)."/quiz-data/";
        $directory = rtrim($directory, "/")."/";
        if (!is_dir($directory)) {
            if (!$create) return false;
            if (!@mkdir($directory, 0775, true) && !is_dir($directory)) return false;
        }
        if (!is_file($directory.".htaccess")) {
            @file_put_contents($directory.".htaccess", "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        if (!is_file($directory."index.html")) @file_put_contents($directory."index.html", "");
        return is_writable($directory) ? $directory : false;
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

    // Certificate registry of versions custom.1 to custom.6, only read and cleaned
    private function getLegacyFile() {
        $file = trim((string)$this->yellow->system->get("quizCertificateFile"));
        return $file!="" ? $file : dirname(__FILE__)."/quiz-certificates.csv";
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

    // Return shortcut arguments: file name and optional title
    private function getArguments($text) {
        if (isset($this->yellow->toolbox) && method_exists($this->yellow->toolbox, "getTextArguments")) {
            $arguments = $this->yellow->toolbox->getTextArguments($text);
        } else {
            $arguments = preg_split('/\s+/', trim($text), 2);
        }
        $fileName = isset($arguments[0]) ? trim($arguments[0]) : "";
        $title = isset($arguments[1]) ? trim($arguments[1], " \t\"'") : "";
        if ($title=="-") $title = "";
        return array($fileName, $title);
    }

    // Return texts needed by quiz.js
    private function getClientTexts() {
        $keys = array("certButton"=>"quizCertButton", "modalTitle"=>"quizModalTitle", "namePrompt"=>"quizNamePrompt",
            "namePlaceholder"=>"quizNamePlaceholder", "close"=>"quizClose",
            "certHeading"=>"quizCertHeading", "certIntro"=>"quizCertIntro", "certCompleted"=>"quizCertCompleted",
            "certMastery"=>"quizCertMastery", "certParts"=>"quizCertParts",
            "awayWarning"=>"quizAwayWarning", "awaySubmitted"=>"quizAwaySubmitted", "jsRequired"=>"quizJsRequired",
            "certScore"=>"quizCertScore", "certDate"=>"quizCertDate", "certNumber"=>"quizCertNumber",
            "timeUp"=>"quizTimeUp", "submitting"=>"quizSubmitting",
            "unansweredTitle"=>"quizUnansweredTitle", "unansweredOne"=>"quizUnansweredOne", "unansweredMany"=>"quizUnansweredMany",
            "unansweredMore"=>"quizUnansweredMore", "unansweredHint"=>"quizUnansweredHint", "unansweredBack"=>"quizUnansweredBack",
            "unansweredBadge"=>"quizUnansweredBadge", "closingSoon"=>"quizClosingSoon", "closedRunning"=>"quizClosedRunning",
            "closingSoonNoLimit"=>"quizClosingSoonNoLimit", "closedRunningNoLimit"=>"quizClosedRunningNoLimit",
            "forget"=>"quizForget", "forgetConfirm"=>"quizForgetConfirm", "forgetRemove"=>"quizForgetRemove", "forgetCancel"=>"quizForgetCancel",
            "certContinue"=>"quizCertContinue", "certWarning"=>"quizCertWarning", "certConfirm"=>"quizCertConfirm",
            "certConfirmYes"=>"quizCertConfirmYes", "certEdit"=>"quizCertEdit", "certIssued"=>"quizCertIssued",
            "certUsed"=>"quizCertUsed", "certError"=>"quizCertError", "certExpired"=>"quizCertExpired",
            "storageError"=>"quizStorageError", "certKept"=>"quizCertKept");
        $texts = array();
        foreach ($keys as $name=>$key) $texts[$name] = $this->text($key);
        $texts["site"] = (string)$this->yellow->system->get("sitename"); // printed instead of the quiz
        return $texts;
    }

    // Return language text, falls back to English when missing


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

    private function isOn($value) {
        return in_array(strtolower(trim((string)$value)), array("1", "yes", "true", "on"));
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

    // Pasted <iframe>, <audio> or <video> code is rebuilt from its address and size only, with the quiz rules:
    // no new tabs, no full screen, no picture-in-picture, no download, nothing loaded before it is needed
    private function renderEmbed($html) {
        if (!preg_match('/<(iframe|audio|video)\b([^>]*)>/i', $html, $matches)) return "<p>".$this->e($html)."</p>";
        $tag = strtolower($matches[1]);
        $attributes = $this->parseAttributes($matches[2]);
        $src = $this->cleanUrl(isset($attributes["src"]) ? $attributes["src"] : "");
        $size = "";
        foreach (array("width", "height") as $name) {
            if (isset($attributes[$name]) && preg_match('/^\d{1,4}(%|px)?$/', $attributes[$name])) $size .= " {$name}=\"".$attributes[$name]."\"";
        }
        if ($tag=="iframe") {
            if ($src=="") return "";
            $title = isset($attributes["title"]) ? $attributes["title"] : "";
            return $this->renderFrame($this->getFrameUrl($src), $title, $size);
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
        $poster = $this->cleanUrl(isset($attributes["poster"]) ? $attributes["poster"] : "");
        return $this->renderVideo($src, $sources, $size, $poster);
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
        $extension = pathinfo(is_string($path) ? strtolower($path) : "", PATHINFO_EXTENSION);
        if (in_array($extension, array("mp3", "m4a", "aac", "ogg", "oga", "opus", "wav", "flac"))) return $this->renderAudio($url, "");
        if (in_array($extension, array("mp4", "m4v", "webm", "ogv", "mov"))) return $this->renderVideo($url, "", "", "");
        return "<img src=\"".$this->e($url)."\" alt=\"{$alt}\" loading=\"lazy\" />";
    }

    // iframe: loaded when it comes near the screen; the sandbox blocks new tabs and leaving the page, full screen is not allowed
    private function renderFrame($src, $title, $size) {
        return "<div class=\"quiz-media quiz-frame\"><iframe src=\"".$this->e($src)."\" title=\"".$this->e($title!="" ? $title : "Embedded content")."\"{$size}".
            " loading=\"lazy\" sandbox=\"allow-scripts allow-same-origin allow-forms\" referrerpolicy=\"strict-origin-when-cross-origin\"".
            " allow=\"autoplay; encrypted-media\"></iframe></div>";
    }

    private function renderAudio($src, $sources) {
        return "<div class=\"quiz-media quiz-sound\"><audio class=\"quiz-audio\" controls preload=\"none\" controlslist=\"nodownload\"".
            ($src!="" ? " src=\"".$this->e($src)."\"" : "").">{$sources}</audio></div>";
    }

    private function renderVideo($src, $sources, $size, $poster) {
        return "<div class=\"quiz-media quiz-movie\"><video class=\"quiz-video\" controls preload=\"none\" playsinline webkit-playsinline".
            " controlslist=\"nodownload nofullscreen noremoteplayback\" disablepictureinpicture disableremoteplayback{$size}".
            ($poster!="" ? " poster=\"".$this->e($poster)."\"" : "").($src!="" ? " src=\"".$this->e($src)."\"" : "").">{$sources}</video></div>";
    }

    // YouTube players get no full screen button and play inside the page
    private function getFrameUrl($src) {
        if (preg_match('~^https://(?:www\.)?youtube(?:-nocookie)?\.com/embed/~', $src)) {
            $src .= (strpos($src, "?")===false ? "?" : "&")."fs=0&playsinline=1&rel=0";
        }
        return $src;
    }

    // Attributes of an HTML tag as name => value
    private function parseAttributes($text) {
        $attributes = array();
        if (preg_match_all('/([a-zA-Z][\w:\-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $text, $list, PREG_SET_ORDER)) {
            foreach ($list as $item) {
                $value = isset($item[4]) && $item[4]!=="" ? $item[4] : (isset($item[3]) && $item[3]!=="" ? $item[3] : $item[2]);
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

    // Micro markdown-like formatting
    public function toHTML($text, $p) {
        $text = htmlspecialchars(trim($text), ENT_QUOTES, "UTF-8");
        if ($text==="") return "";
        $text = preg_replace_callback('/\\\[\\\n]/', function($m) { return $m[0]=="\\\\" ? "\\" : "<br />\n"; }, $text);
        $text = preg_replace("/\*\*(.+?)\*\*/", "<b>$1</b>", $text);
        $text = preg_replace("/\*(.+?)\*/", "<i>$1</i>", $text);
        $text = preg_replace_callback("/!\[(.*?)\]\(((?:https?:\/\/|\/)[^ )]+)\)/", function($m) { return $this->renderShortMedia($m[1], $m[2], $m[0]); }, $text);
        $text = preg_replace("/\[(.*?)\]\((https?:\/\/[^ )]+)\)/", "<a href=\"$2\">$1</a>", $text);
        $text = preg_replace("/(?<![(\">])(https?:\/\/[^\s<\"]+)/", "<a href=\"$1\">$1</a>", $text);
        if ($text[0]=="#") {
            $text = preg_replace_callback('/^(#+)\s*(.*)/', function($m) { $h = min(6, strlen($m[1])); return "<h{$h}>".$m[2]."</h{$h}>"; }, $text);
        } elseif ($p) {
            $text = "<p>".$text."</p>";
        }
        return $text;
    }

    // Handle page extra data
    public function onParsePageExtra($page, $name) {
        $output = null;
        if ($name=="header") {
            $assetLocation = $this->yellow->system->get("coreServerBase").$this->yellow->system->get("coreAssetLocation");
            $version = rawurlencode(self::VERSION);
            $output .= "<script type=\"text/javascript\" defer=\"defer\" src=\"{$assetLocation}quiz.js?v={$version}\"></script>\n";
            $output .= "<link rel=\"stylesheet\" type=\"text/css\" media=\"all\" href=\"{$assetLocation}quiz.css?v={$version}\" />\n";
        }
        return $output;
    }
}
