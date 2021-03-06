import itauJson from '@hipay/hipay-cypress-utils/fixtures/payment-means/itau.json';
import caixaJson from "@hipay/hipay-cypress-utils/fixtures/payment-means/caixa";

describe('Pay by Itau', function () {

    before(function () {

        cy.logToAdmin();
        cy.goToPaymentsTab();
        cy.goToAdminHipayConfig();
        cy.activateAstropayMethods();
        cy.goToPaymentsTab();
        cy.activatePaymentMethods("itau");
        cy.switchWooCurrency("BRL");
        cy.adminLogOut();
    });

    after(function () {

        cy.logToAdmin();
        cy.switchWooCurrency("EUR");
        cy.adminLogOut();
    });

    beforeEach(function () {
        cy.selectItemAndGoToCart();
        cy.addProductQuantity(2);
        cy.goToCheckout();
        cy.fillBillingForm("BR");
    });

    afterEach(() => {
        cy.saveLastOrderId();
    });

    it('Pay by Itau', function () {

        cy.get('[for="payment_method_hipayenterprise_itau"]').click({force: true});
        cy.wait(3000);

        cy.fill_hostedfields_input("#hipay-itau-field-national_identification_number", itauJson.data.national_identification_number);

        cy.get('#place_order').click({force: true});
        cy.payAndCheck('payitau', itauJson.url, "itau");
    });
});
