<link rel="stylesheet" href="<?=asset('public/css/submission.css')?>">
<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> Payroll</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="submission">Submission</span>
        </h5>
    </nav>
    <div class="mb-4">
        <h5 class="text-secondary fw-bold m-0">
            <i class="fa-solid fa-coins"></i>
            <span data-i18n="company_management_title">Payroll Submission and Payment</span>
        </h5>
        <p class="text-muted small m-0 mt-1" data-i18n="company_management_description">Submit payroll data and transfer salaries</p>
    </div>
    <div class="stepper">
        <div class="step done"><div class="step-badge"><i class="fa-solid fa-check"></i></div><span class="step-label">Close Payroll Period</span></div>
        <div class="step-line done"></div>
        <div class="step done"><div class="step-badge"><i class="fa-solid fa-check"></i></div><span class="step-label">Verify Amount</span></div>
        <div class="step-line" id="line2"></div>
        <div class="step" id="stepSubmit"><div class="step-badge">3</div><span class="step-label">Submit & Transfer</span></div>
        <div class="step-line" id="line3"></div>
        <div class="step" id="stepDone"><div class="step-badge">4</div><span class="step-label">Completed</span></div>
    </div>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="ticket h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="kpi-label">ประกันสังคม (สปส.)</span>
                    <i class="fa-solid fa-shield-halved" style="color:var(--accent);"></i>
                </div>
                <div class="mono kpi-value" id="ssoAmountLabel">-</div>
                <div class="mt-2" id="ssoStatusPill"></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="ticket h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="kpi-label">โอนเงินเดือนผ่านธนาคาร</span>
                    <i class="fa-solid fa-building-columns" style="color:var(--accent);"></i>
                </div>
                <div class="mono kpi-value" id="bankAmountLabel">-</div>
                <div class="mt-2" id="bankStatusPill"></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="ticket h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="kpi-label">กรมสรรพากร (ภ.ง.ด.1)</span>
                    <i class="fa-solid fa-landmark" style="color:var(--accent);"></i>
                </div>
                <div class="mono kpi-value" id="taxAmountLabel">-</div>
                    <div class="mt-2" id="taxStatusPill"></div>
                </div>
            </div>
        </div>
        <div class="d-flex flex-column gap-3">
            <div class="section-card">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
                    <div>
                        <h3 class="display section-title">นำส่งประกันสังคม</h3>
                        <div class="section-desc">เชื่อมต่อระบบ e-Service สำนักงานประกันสังคม (สปส. 1-10) · กำหนดนำส่งภายในวันที่ 15 ของเดือนถัดไป</div>
                    </div>
                    <span id="ssoHeaderPill"></span>
                </div>
                <div class="row g-3 my-2">
                    <div class="col-sm-4"><div class="kpi-label">จำนวนพนักงาน</div><div class="mono fw-medium" id="ssoHeadcount">-</div></div>
                    <div class="col-sm-4"><div class="kpi-label">สมทบฝั่งลูกจ้าง</div><div class="mono fw-medium" id="ssoEmployeeTotal">-</div></div>
                    <div class="col-sm-4"><div class="kpi-label">สมทบฝั่งนายจ้าง</div><div class="mono fw-medium" id="ssoEmployerTotal">-</div></div>
                </div>
                <div class="d-flex gap-2 flex-wrap mb-3">
                    <button class="btn-outline-ledger" id="ssoExportBtn"><i class="fa-solid fa-file-arrow-down me-2"></i>สร้างไฟล์นำส่ง (รูปแบบ สปส.)</button>
                    <button class="btn-ledger" id="ssoSubmitBtn"><i class="fa-solid fa-paper-plane me-2"></i>นำส่งข้อมูลไปสำนักงานประกันสังคม</button>
                </div>
                <div id="ssoReceipt" class="mb-2" style="display:none; color:var(--positive); background:var(--positive-soft); padding:8px 12px; border-radius:8px;"></div>
                    <div class="table-scroll">
                        <table class="ledger-table table table-borderless mb-0">
                            <thead>
                                <tr>
                                    <th>ชื่อ-สกุล</th>
                                    <th>แผนก</th>
                                    <th class="text-end">สมทบลูกจ้าง</th>
                                    <th class="text-end">สมทบนายจ้าง</th>
                                    <th class="text-end">รวม</th>
                                    <th>สถานะ</th>
                                </tr>
                            </thead>
                            <tbody id="ssoTableBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="section-card">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
                        <div>
                            <h3 class="display section-title">เชื่อมต่อธนาคารเพื่อโอนเงินเดือน (Cash Management)</h3>
                            <div class="section-desc">เชื่อมระบบ Corporate Cash Link ของธนาคารเพื่อสร้างไฟล์โอนเงินเดือนจำนวนมาก (Bulk Transfer)</div>
                        </div>
                        <span id="bankHeaderPill"></span>
                    </div>
                    <div class="row g-3 align-items-end my-2">
                        <div class="col-sm-5">
                            <label class="kpi-label d-block mb-1">ธนาคารต้นทาง (บัญชีเงินเดือนบริษัท)</label>
                            <select id="bankConnectorSelect" class="form-select"></select>
                        </div>
                        <div class="col-sm-4">
                            <div class="kpi-label">ยอดโอนรวมงวดนี้</div>
                            <div class="mono fw-medium" id="bankTotalAmount">-</div>
                        </div>
                        <div class="col-sm-3">
                            <div class="kpi-label">จำนวนบัญชีปลายทาง</div>
                            <div class="mono fw-medium" id="bankHeadcount">-</div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap mb-3 mt-2">
                        <button class="btn-outline-ledger" id="bankExportBtn"><i class="fa-solid fa-file-arrow-down me-2"></i>สร้างไฟล์โอนเงินเดือน (Bulk file)</button>
                        <button class="btn-ledger" id="bankSubmitBtn"><i class="fa-solid fa-money-bill-transfer me-2"></i>ยืนยันการโอนเงินผ่านธนาคาร</button>
                    </div>
                    <div id="bankReceipt" class="mb-2" style="display:none; color:var(--positive); background:var(--positive-soft); padding:8px 12px; border-radius:8px;"></div>
                        <div class="table-scroll">
                            <table class="ledger-table table table-borderless mb-0">
                                <thead>
                                    <tr>
                                        <th>ชื่อ-สกุล</th>
                                        <th>ธนาคารปลายทาง</th>
                                        <th>เลขที่บัญชี</th>
                                        <th class="text-end">ยอดโอน</th>
                                        <th>สถานะ</th>
                                    </tr>
                                </thead>
                                <tbody id="bankTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="section-card">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
                            <div>
                                <h3 class="display section-title">เชื่อมต่อกรมสรรพากร</h3>
                                <div class="section-desc">ยื่นแบบภาษีเงินได้หัก ณ ที่จ่าย (ภ.ง.ด.1) ผ่านระบบ e-Withholding Tax / e-Filing · กำหนดยื่นภายในวันที่ 7 (ยื่นออนไลน์ขยายถึงวันที่ 15) ของเดือนถัดไป</div>
                            </div>
                            <span id="taxHeaderPill"></span>
                        </div>
                        <div class="row g-3 my-2">
                            <div class="col-sm-4"><div class="kpi-label">จำนวนพนักงานในแบบยื่น</div><div class="mono fw-medium" id="taxHeadcount">-</div></div>
                            <div class="col-sm-4"><div class="kpi-label">ยอดภาษีหัก ณ ที่จ่ายรวม</div><div class="mono fw-medium" id="taxTotal">-</div></div>
                            <div class="col-sm-4"><div class="kpi-label">เลขที่อ้างอิงการยื่นล่าสุด</div><div class="mono fw-medium" id="taxRefLabel">ยังไม่ได้ยื่น</div></div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap mb-3">
                            <button class="btn-outline-ledger" id="taxExportBtn"><i class="fa-solid fa-file-arrow-down me-2"></i>สร้างไฟล์แบบ ภ.ง.ด.1</button>
                            <button class="btn-ledger" id="taxSubmitBtn"><i class="fa-solid fa-paper-plane me-2"></i>ยื่นแบบผ่านระบบ e-Filing</button>
                        </div>
                        <div id="taxReceipt" class="mb-2" style="display:none; color:var(--positive); background:var(--positive-soft); padding:8px 12px; border-radius:8px;"></div>
                        <div class="connector-row">
                            <div class="d-flex align-items-center gap-3">
                                <div class="connector-icon"><i class="fa-solid fa-landmark"></i></div>
                                <div>
                                    <div>RD e-Filing (กรมสรรพากร)</div>
                                    <div>แบบ ภ.ง.ด.1 · e-Withholding Tax</div>
                                </div>
                            </div>
                            <span id="taxConnStatus"></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="toast-container toast-ledger">
                <div id="mainToast" class="toast align-items-center border-0" role="alert" style="background:var(--primary); color:#fff;">
                    <div class="d-flex">
                        <div class="toast-body" id="toastBody"></div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?=asset('public/js/submission.js')?>"></script>