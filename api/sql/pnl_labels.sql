-- P&L page UI labels (cs + en). Idempotent. Most were missing so the page showed raw keys.
INSERT INTO translations (label_key, lang, translation) VALUES
 ('col_date','cs','Datum'),               ('col_date','en','Date'),
 ('col_ticker','cs','Ticker'),            ('col_ticker','en','Ticker'),
 ('col_qty','cs','Množství'),             ('col_qty','en','Quantity'),
 ('col_gross_profit','cs','Hrubý zisk'),  ('col_gross_profit','en','Gross profit'),
 ('col_net_profit','cs','Čistý zisk'),    ('col_net_profit','en','Net profit'),
 ('col_fx','cs','Kurzový rozdíl'),        ('col_fx','en','FX difference'),
 ('col_fees','cs','Poplatky'),            ('col_fees','en','Fees'),
 ('col_tax_test','cs','Daňový test'),     ('col_tax_test','en','Tax test'),
 ('col_days','cs','Dní drženo'),          ('col_days','en','Days held'),
 ('pnl_net_profit','cs','Čistý zisk'),    ('pnl_net_profit','en','Net profit'),
 ('pnl_winning','cs','Ziskové'),          ('pnl_winning','en','Winning'),
 ('pnl_losing','cs','Ztrátové'),          ('pnl_losing','en','Losing'),
 ('pnl_fx','cs','Kurzový rozdíl'),        ('pnl_fx','en','FX difference'),
 ('pnl_fees','cs','Poplatky'),            ('pnl_fees','en','Fees'),
 ('pnl_tax_free','cs','Daňově osvobozené'),('pnl_tax_free','en','Tax-free'),
 ('loading_pnl','cs','Načítám P&L…'),     ('loading_pnl','en','Loading P&L…'),
 ('no_sales','cs','Žádné realizované obchody'),('no_sales','en','No realized trades'),
 ('trades_count','cs','obchodů'),         ('trades_count','en','trades'),
 ('test_passed','cs','Prošel'),           ('test_passed','en','Passed'),
 ('test_failed','cs','Neprošel'),         ('test_failed','en','Failed'),
 ('refresh','cs','Obnovit'),              ('refresh','en','Refresh')
ON CONFLICT (label_key, lang) DO UPDATE SET translation = EXCLUDED.translation;
