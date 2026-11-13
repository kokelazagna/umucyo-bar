# Final demonstration script (17 steps)

Every number below was checked by running this exact script on a fresh database.

## Before you start

1. In phpMyAdmin: drop `bar_management_db`, then import `database/schema.sql` and `database/seed.sql`.
2. Open `http://localhost/bssbms3mod/`.
3. Have the project open in VS Code in case you are asked to show the code.

The seed data already contains: 5 beverage categories, 7 products with **0 stock**, 3 suppliers, 2 customers, 4 users.

## The steps

| # | What to do | Data to type | Expected result |
|---|------------|--------------|-----------------|
| 1 | **Log in as administrator** | username `admin`, password `Admin@123` | The dashboard opens. |
| 0 | **Cash Management -> Open session** (needed before any cash payment) | Opening cash `100000` | Cash session #1 is open. |
| 2 | **Categories -> add** | Name `Spirits`, description `Whisky, gin and other spirits` | 6 categories. |
| 3 | **Products -> New product** | Name `Skol 50cl`, category `Beer`, brand `Skol`, barcode `6001000000080`, base unit `Bottle`, purchase price `700`, selling price `1000`, minimum stock `20`. Click **+ Add unit**: `Crate` contains `20` bottles, price `19000` | Product `Skol 50cl` is saved with 0 stock. |
| 4 | **Suppliers -> New supplier** | Name `Kigali Wholesale`, business `Kigali Wholesale Ltd`, phone `0788300001` | Supplier registered. |
| 5 | **Purchases -> New purchase** | Supplier `Kigali Wholesale`, today's date. Lines: `Skol 50cl` **crate** x `5` at `14000`; `Coca-Cola 30cl` **crate** x `2` at `12000`; `Drinking Water 50cl` **pack** x `2` at `3600`. Amount paid `40000`, method `Cash`. Click **Save purchase** | Total **101,200**, paid 40,000, balance **61,200**. Status **pending**. Open Products: stock is still **0** (stock never increases before the goods are confirmed). |
| 6 | **Open the purchase -> Confirm & receive stock**, then check **Products** | - | Skol **100** bottles (shown as 5 crates + 0 bottles), Coca-Cola **48**, Water **24**. Average cost: Skol **700**, Coca-Cola **500**, Water **300**. |
| 7 | **Customers -> New customer** | Name `Alice Uwase`, phone `0788400001`, credit limit `30000` | Customer registered (only a manager or the owner can set the credit limit). |
| 8 | **Point of Sale** -> select customer `Alice Uwase`; click `Skol 50cl` 5 times, `Coca-Cola 30cl` 3 times, `Drinking Water 50cl` twice | - | Total **8,100 RWF** (5 x 1,000 + 3 x 700 + 2 x 500). |
| 9 | **Payment**: Cash `5000`, leave the rest on credit. Click **Complete sale**. Then make a second sale to a walk-in customer: 1 **crate** of `Skol 50cl`, payment `Mobile Money` `19000` | - | Receipt 1: paid 5,000, **on credit 3,100** (Alice's debt = 3,100). Receipt 2: 19,000 by Mobile Money. This shows cash, credit, Mobile Money and the crate unit. |
| 10 | **Check Products** | - | Skol **75** (5 bottles + 1 crate = 25 sold), Coca-Cola **45**, Water **22**. |
| 11 | **Customers -> Alice -> Statement -> Receive a debt payment** | Amount `1000`, method `Cash` | Debt goes from 3,100 to **2,100**. |
| 12 | **Expenses -> New expense** | Category `Transport`, amount `2000`, method `Cash`, description `Delivery transport` | Expense saved and taken from the till. |
| 13 | **Stock Control -> + Stock adjustment** | Type `Damaged`, product `Skol 50cl`, quantity `2`, reason `2 bottles broken` | Skol goes from 75 to **73**. The movement shows -2 with your name. Loss at cost: 1,400 RWF. |
| 14 | **Reports -> Daily sales report** | today's date | 2 sales, revenue **27,100**; cash 5,000, Mobile Money 19,000, credit 3,100. |
| 15 | **Reports -> Stock balance report** | - | Stock value at cost **80,200** (73 x 700 + 45 x 500 + 22 x 300). |
| 16 | **Reports -> Gross profit report**, then **Net profit report** | today's date | Revenue 27,100 - cost of goods 19,600 = gross profit **7,500** (27.7%). Minus expenses 2,000 = net profit **5,500**. |
| 17 | **Cash Management -> session #1 -> Close session** | Counted cash `64000` | Expected cash = 100,000 - 40,000 (supplier) + 5,000 (sale) + 1,000 (debt) - 2,000 (expense) = **64,000**. Variance **0**. |

## Things to point out while you demonstrate

* **Crate and bottle conversion (step 5, 9, 10):** stock is stored in bottles. Buying 5 crates adds 100 bottles; selling 1 crate removes 20; selling single bottles still works. 1 crate bought and 7 bottles sold leaves 13 bottles, not a crate.
* **Real profit (step 16):** each sale line stores the average cost at the moment of the sale, so profit is cost-based and does not change when prices change later.
* **Credit is not cash (step 9, 17):** Alice's 3,100 is a debt, not cash received, so it is not in the cash drawer. Mobile Money (19,000) is not in the drawer either.
* **One transaction (step 8):** if anything fails while saving a sale, nothing is saved. Try selling more than the stock: the sale is refused and stock stays correct.
* **Accountability:** open **Audit Log** to show every action with user, date and details, and **Stock Control** to show every stock movement.

## Optional extras (2 minutes each)

* **Roles:** log in as `cashier` (password `Admin@123`): no discount box, no purchases, no reports, no users. Log in as `stock`: stock pages and stock reports only.
* **Credit limit warning:** sell more than 30,000 RWF on credit to Alice: refused. A manager or the owner can tick **Authorize over limit**.
* **Cancel a sale:** open a receipt, cancel it with a reason: stock returns, a stock-reversal movement is written, and the cash is refunded from the till.
* **Backup (owner only):** **Backup & Restore -> Download backup**. Keep the file safe.
* **Excel export:** open any report and click **Excel (.xlsx)**.
