/**
 * Bizy Google Sheet → Save Rack CRM leads webhook
 *
 * SETUP
 * 1. Open the spreadsheet → Extensions → Apps Script
 * 2. Paste this file and Save
 * 3. Project Settings → Script properties → add:
 *      LEADS_WEBHOOK_URL   = https://app.saverack.com/api/leads/webhooks/bizy
 *      LEADS_WEBHOOK_SECRET = (same value as LEADS_BIZY_WEBHOOK_SECRET in .env)
 * 4. Run authorizeOnce() once and approve permissions
 * 5. Run installTriggers() once (installs onChange + every-5-min backup sync)
 * 6. Optional: run syncUnimportedRows() once to backfill only blank "CRM Sync" rows
 *
 * Sheet requirements
 * - Row 1 headers: Company | Website | Email | Response | Status
 * - Script adds a "CRM Sync" column if missing; writes imported / duplicate / error there
 * - Existing CRM leads are skipped by email (duplicate) — safe if rows already exist
 * - Response → lead comment; Status is appended as "Sheet status: …" (CRM status stays Open)
 */

var CRM_SYNC_HEADER = 'CRM Sync';
var HEADER_ROW = 1;

/** Exact Bizy sheet columns (row 1). */
var COL = {
  company: 'Company',
  website: 'Website',
  email: 'Email',
  response: 'Response',
  status: 'Status',
};

function authorizeOnce() {
  var props = PropertiesService.getScriptProperties();
  Logger.log('URL set: ' + !!props.getProperty('LEADS_WEBHOOK_URL'));
  Logger.log('Secret set: ' + !!props.getProperty('LEADS_WEBHOOK_SECRET'));
  SpreadsheetApp.getActiveSpreadsheet().getName();
}

function installTriggers() {
  var ss = SpreadsheetApp.getActive();
  ScriptApp.getProjectTriggers().forEach(function (t) {
    var h = t.getHandlerFunction();
    if (h === 'onSheetChange' || h === 'syncUnimportedRows') {
      ScriptApp.deleteTrigger(t);
    }
  });
  ScriptApp.newTrigger('onSheetChange').forSpreadsheet(ss).onChange().create();
  ScriptApp.newTrigger('syncUnimportedRows').timeBased().everyMinutes(5).create();
  Logger.log('Triggers installed: onChange + every 5 minutes');
}

function onSheetChange(e) {
  try {
    if (!e || (e.changeType !== 'INSERT_ROW' && e.changeType !== 'EDIT')) {
      return;
    }
    // Small delay so the new row values are fully written
    Utilities.sleep(1500);
    syncUnimportedRows();
  } catch (err) {
    Logger.log('onSheetChange error: ' + err);
  }
}

/**
 * Import every data row that has email + company and an empty CRM Sync cell.
 */
function syncUnimportedRows() {
  var sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
  var lastRow = sheet.getLastRow();
  var lastCol = sheet.getLastColumn();
  if (lastRow <= HEADER_ROW || lastCol < 1) {
    return;
  }

  var syncCol = ensureCrmSyncColumn_(sheet);
  lastCol = Math.max(lastCol, syncCol);

  var headers = sheet.getRange(HEADER_ROW, 1, 1, lastCol).getDisplayValues()[0];
  var headerIndex = {};
  for (var c = 0; c < headers.length; c++) {
    var h = String(headers[c] || '').trim();
    if (h) {
      headerIndex[normalizeHeader_(h)] = c;
    }
  }

  var values = sheet.getRange(HEADER_ROW + 1, 1, lastRow - HEADER_ROW, lastCol).getDisplayValues();
  for (var i = 0; i < values.length; i++) {
    var rowNumber = HEADER_ROW + 1 + i;
    var row = values[i];
    var syncVal = String(row[syncCol - 1] || '').trim();
    if (syncVal !== '') {
      continue;
    }

    var fields = {};
    for (var key in headerIndex) {
      if (!Object.prototype.hasOwnProperty.call(headerIndex, key)) {
        continue;
      }
      if (key === normalizeHeader_(CRM_SYNC_HEADER)) {
        continue;
      }
      var cell = String(row[headerIndex[key]] || '').trim();
      if (cell) {
        fields[key] = cell;
      }
    }

    // Prefer original header labels for CRM mapping
    var labeled = {};
    for (var c2 = 0; c2 < headers.length; c2++) {
      var label = String(headers[c2] || '').trim();
      if (!label || normalizeHeader_(label) === normalizeHeader_(CRM_SYNC_HEADER)) {
        continue;
      }
      var v = String(row[c2] || '').trim();
      if (v) {
        labeled[label] = v;
      }
    }

    var company = cellByHeader_(labeled, fields, COL.company, [
      'Company Name',
      'Business',
      'Business Name',
      'company_name',
      'company',
    ]);
    var email = cellByHeader_(labeled, fields, COL.email, [
      'Email Address',
      'E-mail',
      'email',
    ]);

    if (!company || !email) {
      continue;
    }

    var website = cellByHeader_(labeled, fields, COL.website, [
      'Website URL',
      'Store Website URL',
      'URL',
      'website',
    ]);
    var responseText = cellByHeader_(labeled, fields, COL.response, [
      'Comment',
      'Comments',
      'Notes',
      'Note',
      'Email Thread',
      'Message',
      'comment',
    ]);
    var sheetStatus = cellByHeader_(labeled, fields, COL.status, ['Lead Status']);

    var result = postLead_({
      company_name: company,
      email: email,
      website: website || null,
      name: null,
      Response: responseText || null,
      Status: sheetStatus || null,
      source: 'google_sheets',
      sheet_row: rowNumber,
      fields: {
        Company: company,
        Website: website,
        Email: email,
        Response: responseText,
        Status: sheetStatus,
      },
    });

    var stamp = Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'yyyy-MM-dd HH:mm:ss');
    var cellValue = stamp;
    if (result && result.status === 'created') {
      cellValue = 'imported ' + stamp + ' #' + (result.lead_id || '');
    } else if (result && result.status === 'duplicate') {
      cellValue = 'duplicate ' + stamp + ' #' + (result.lead_id || '');
    } else if (result && result.error) {
      cellValue = 'error ' + stamp + ': ' + String(result.error).substring(0, 180);
    } else {
      cellValue = 'error ' + stamp;
    }
    sheet.getRange(rowNumber, syncCol).setValue(cellValue);

    // Be gentle on the CRM
    Utilities.sleep(250);
  }
}

function ensureCrmSyncColumn_(sheet) {
  var lastCol = Math.max(sheet.getLastColumn(), 1);
  var headers = sheet.getRange(HEADER_ROW, 1, 1, lastCol).getDisplayValues()[0];
  for (var i = 0; i < headers.length; i++) {
    if (String(headers[i] || '').trim().toLowerCase() === CRM_SYNC_HEADER.toLowerCase()) {
      return i + 1;
    }
  }
  var col = lastCol + 1;
  sheet.getRange(HEADER_ROW, col).setValue(CRM_SYNC_HEADER);
  return col;
}

function normalizeHeader_(value) {
  return String(value || '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '');
}

function pickField_(labeled, normalized, candidates) {
  var i;
  for (i = 0; i < candidates.length; i++) {
    var c = candidates[i];
    if (labeled[c] && String(labeled[c]).trim()) {
      return String(labeled[c]).trim();
    }
  }
  for (i = 0; i < candidates.length; i++) {
    var key = normalizeHeader_(candidates[i]);
    if (normalized[key] && String(normalized[key]).trim()) {
      return String(normalized[key]).trim();
    }
  }
  return '';
}

/** Prefer the exact sheet header, then fallback aliases. */
function cellByHeader_(labeled, normalized, primaryHeader, aliases) {
  var primary = pickField_(labeled, normalized, [primaryHeader]);
  if (primary) {
    return primary;
  }
  return pickField_(labeled, normalized, aliases || []);
}

function postLead_(payload) {
  var props = PropertiesService.getScriptProperties();
  var url = String(props.getProperty('LEADS_WEBHOOK_URL') || '').trim();
  var secret = String(props.getProperty('LEADS_WEBHOOK_SECRET') || '').trim();
  if (!url || !secret) {
    return { error: 'Missing LEADS_WEBHOOK_URL or LEADS_WEBHOOK_SECRET script property' };
  }

  try {
    var response = UrlFetchApp.fetch(url, {
      method: 'post',
      contentType: 'application/json',
      headers: {
        'X-Leads-Webhook-Secret': secret,
      },
      payload: JSON.stringify(payload),
      muteHttpExceptions: true,
    });
    var code = response.getResponseCode();
    var bodyText = response.getContentText() || '{}';
    var body = {};
    try {
      body = JSON.parse(bodyText);
    } catch (parseErr) {
      return { error: 'HTTP ' + code + ' non-JSON: ' + bodyText.substring(0, 120) };
    }
    if (code === 201 || code === 200) {
      return body;
    }
    var msg = body.message || bodyText;
    if (body.errors) {
      msg += ' ' + JSON.stringify(body.errors);
    }
    return { error: 'HTTP ' + code + ': ' + msg };
  } catch (err) {
    return { error: String(err) };
  }
}
