# Translation Style Guide

Guidance for writing/editing `public/lang/en.json` and `public/lang/th.json`. Both files share the
exact same flat key set — every key must exist in both, and both values should each read as
natural, independently-authored copy for the same UI concept, not a translation of the other
language's sentence structure.

Written 2026-08-29/30 after a full-corpus audit (~1,890 shared keys) found the corpus already
mostly natural — these rules capture the patterns that were already dominant and correct, plus the
handful of outliers that got fixed alongside this guide (see `git log` on this date for the diff).

## Rules

1. **Confirm dialogs: `[Verb] this [noun]?`, no throat-clearing.**
   Title is the question itself; the message below it explains the consequence, not a restatement
   of the question.
   - Good: title `"Lock this entry?"` / message `"Once locked, this entry can no longer be edited or deleted."`
   - Good (TH): title `"ล็อกรายการนี้?"` / message `"เมื่อล็อกแล้วจะแก้ไขหรือลบไม่ได้อีก"`
   - Avoid: `"Are you sure you want to...?"`, `"Do you want to...?"`, `"ต้องการ...ใช่หรือไม่?"`,
     `คุณต้องการ...` — these were real outliers found and fixed (`confirm_cancel_message`,
     `confirm_delete_message`, `delete_confirm_question`, `confirm_toggle_status_question`).

2. **No gendered politeness particles (ครับ/ค่ะ) anywhere in Thai UI copy.** The app has no fixed
   speaker gender, and mixing genders across different keys reads as inconsistent. This has held
   consistently across the whole corpus — keep it that way.

3. **Buttons and labels: verb + object, or just the object. No "Please".** `"Save"`, `"Cancel"`,
   `"Reject"`, not `"Please save"`. A dropdown's empty placeholder is `"Select an option"` /
   `"เลือกตัวเลือก"`, not `"Please choose."` / `"กรุณาเลือก"`.

4. **Reserve "the system will..." (`ระบบจะ...`) for behavior that happens automatically, without
   the user acting.** Don't use it as a generic subject filler for something the user themselves
   just triggered by clicking a button.

5. **A long hint/description is fine when the underlying feature is genuinely complex — length
   isn't the problem, vagueness is.** Prefer one precise sentence that names the actual mechanism
   (which table gets written, what happens on save, what the fallback behavior is) over a short
   but vague platitude. Most of this app's own `*_hint`/`*_description` keys already do this
   correctly for genuinely intricate payroll/approval logic — don't trim them just to shorten them.

6. **Always use the established Thai payroll/HR/statutory term over a generic paraphrase.**
   สปส., ภ.ง.ด., กยศ., เงินได้/เงินหัก, บัญชีเงินเดือน, งวดเงินเดือน, etc. — these are the terms a
   real Thai HR/payroll professional expects to see verbatim. If a new key needs one of these
   concepts, grep existing keys for the term this app already settled on and reuse it rather than
   inventing a new paraphrase.

7. **Keep terminology consistent for the same concept across different keys.** Don't call the same
   underlying thing "รายการ" in one key and "ข้อมูล"/"เรคคอร์ด" in another without a real reason —
   check sibling keys in the same feature area before picking a new noun.

8. **Error messages: state what failed, plainly. No apology filler.** `"Failed to save data."` /
   `"บันทึกข้อมูลไม่สำเร็จ"`, not `"Sorry, something went wrong while saving."` This has held
   consistently across the corpus's `*_failed` keys — keep it.

9. **English and Thai should each be independently natural for the same concept**, not a
   word-for-word rendering of the other's word order/article/preposition choices. When editing one
   side, don't just translate the other side's sentence structure — ask what a native speaker
   product writer would independently write for that same UI moment.

## Where the fallback English text lives

Several JS files carry a hardcoded English fallback (`langData['key'] || 'Some Text'`) for when a
key is somehow missing at runtime — these are a safety net, not primary content, and don't need to
be kept in lockstep with every wording tweak to the JSON. The exception is a **static fallback
baked directly into view HTML** via `data-i18n="key"` (the text between the tags, shown briefly
before `applyLanguage()` runs, or if JS fails to load) — update that text alongside the JSON key
whenever you change one, since it's the same string rendering in the same place.

## Process for future edits

- When adding a NEW key: write both EN and TH from scratch, independently, following the rules
  above — don't machine-translate one into the other and call it done.
- When fixing an EXISTING key that reads awkwardly: check 2-3 sibling keys in the same feature
  area first (e.g. other `confirm_*_title` keys) so the fix lands on the pattern this app already
  established, not a fourth new variant.
- Keep both `en.json` and `th.json` valid JSON after every edit (`node -e "JSON.parse(...)"` on
  both files) — a missing comma in one breaks every translated string on every page, not just the
  one you touched.
