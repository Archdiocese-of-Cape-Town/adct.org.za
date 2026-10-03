# Sending a parish event in

This guide is for parish staff who want an event to appear on the
Archdiocese events page and in the public calendar.

You do not need to install anything and you do not need a WordPress
account. You only need to send an email.

## The short version

1. Write the notice the way you would write it for your bulletin.
2. Email it to `events@adct.org.za`.
3. Check your inbox for a preview email. Correct anything that is wrong.
   Press **Yes, publish this**.
4. Wait for a dean or the archdiocese to approve it.

Nothing goes on the public events page until step 4 is finished. That is
deliberate.

## How to write the notice

Write it as plain text or in the body of the email. **Send the event as the
body of the email, not only as a Word or PDF attachment.** A PDF poster is
read automatically, but a plain email is always read more reliably.

Include these, in your own words:

| What to include | Example |
|---|---|
| The name of the event | ParishConfirmationRetreat |
| The date, written out | Saturday 12 October 2026 |
| The start time | from 09:00 |
| Where it happens | the parish church, or the hall if it is different |
| The parish name | so we know which parish it is for |
| How to find out more | a parish phone number or office email, not a private one |

A few things that help a great deal:

- **Write the day name in full.** "Saturday 12 October 2026" is read
  correctly. "12/10" is ambiguous: it could be 12 October or 10 December.
  The plugin assumes day-first, so `12/10/2026` means 12 October, but
  writing the month in full removes all doubt.
- **Say which year.** A notice that only says "12 October" is assumed to be
  the next one.
- **Say what time it ends** if it does not run all day. Leave it out if the
  event is open-ended.
- **Use one event per section.** If your bulletin has Mass times, the
  collection list and then an event, give the event its own heading, for
  example EVENTS. The plugin skips the other sections on purpose.
- **Attach the poster as well as writing the text**, if you have one. The
  text is what gets read; the poster is what people see on the day.

## What happens next

| Stage | What you see |
|---|---|
| Your email arrives | Nothing. This is normal. |
| It is read | Nothing yet. This usually takes up to 10 minutes. |
| You get a preview email | A list of the events that were found, with anything uncertain highlighted. |
| You confirm | Press **Yes, publish this**. Or **No, something is wrong** to start again. |
| It is approved | A dean or the archdiocese checks it. You may get an email asking for a correction. |
| It is published | The event appears on the events page and in the calendar. |

The preview email is the important one. It is a **question**, not a
confirmation: nothing is done until you answer it. If you ignore it, the
event stays a draft and is not published.

Check the spam folder if you have not heard back. The preview is sent to the
address the notice came from, so it arrives in the same inbox.

## Problems and what to do about them

| Problem | What to do |
|---|---|
| Nothing arrived at all | Check the sending address is the parish's official office email. Personal addresses are not linked to a parish yet. |
| No preview email after about 30 minutes | Check the spam folder. Then ask the website administrator to look at **Parish Intake → Inbox**. |
| The preview has no event in it | The notice was probably all poster, or all Mass times and intentions. Write the event as text in the email body and send it again. |
| The preview has the wrong date | Press **No, something is wrong** and send a corrected email, or reply telling the archdiocese what the real date is. |
| A day or month is swapped | Rewrite the date with the month spelled out and send it again. `12/10/2026` is read as 12 October. |
| An event is missing from the preview | Add it as its own paragraph with its own heading, so the parser cannot treat it as part of another section. |
| The event is a photo of a poster only | The text has to be typed. Open the poster, read the date, time and place, and put them in the email body as text. |
| The venue is wrong | The event was matched to the wrong parish or hall. Tell the archdiocese which church or hall it is, and it will be corrected before approval. |
| You sent it twice by mistake | Do not worry. A reviewer will see both and keep the correct one. Say which notice to keep if they ask. |
| You need to change an approved event | Send a new email saying what changed. Do not email a second copy of the event. |
| You need the event taken off | Email `events@adct.org.za` and say which event, in the first line. |

## About photographs of posters

The plugin can read a PDF that was sent as an attachment, as long as the PDF
contains text rather than a scan.

A **photograph** of a poster has no text the plugin can read. The button that
reads a poster image runs in the reviewer's own browser, not in your email,
so there is nothing you need to do differently: it will be handled by
somebody at the archdiocese when they review it.

If you want to be certain an event is picked up, always include the event
details as text in the email body.

## Privacy

- The address you send from becomes a known sender for your parish.
- The archdiocese keeps the original email so it can be checked later.
- Nothing from your email is shown on the public events page. Contact
  details are kept private and are never published.
- If an address should no longer be used, ask the archdiocese to remove it.

See [Operator guide](operator-guide.md) for the archdiocese side, and
[Approver guide](approver-guide.md) for the person who approves events.