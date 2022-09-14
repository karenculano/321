import pytest
from pytest_testrail.plugin import pytestrail

from core.common.entities import RevisionStatus
from core.common.utils import get_id_from_response
from steps.backend_steps.tax_rates_steps import TaxRates, TaxRatesAssertionSteps


@pytestrail.case('C2702')
@pytest.mark.smoke
def test_create_tax_rate():
    tax_rate = TaxRates()
    assertion_steps = TaxRatesAssertionSteps(tax_rate)

    tax_rate.compose_create_payload().create()
    assertion_steps.validate_post_response_is_correct()

    tax_rate.get_by_id()
    assertion_steps.validate_get_response_is_correct()


@pytestrail.case('C2703')
@pytest.mark.smoke
def test_update_tax_rate():
    tax_rate = TaxRates()
    assertion_steps = TaxRatesAssertionSteps(tax_rate)

    tax_rate.compose_create_payload().create()
    assertion_steps.validate_post_response_is_correct()

    tax_rate.compose_update_payload().update()
    assertion_steps.check_update_response_is_successful()

    tax_rate.get_by_id()
    assertion_steps.validate_get_response_is_correct(
        expected_response=tax_rate.generate_expected_response_after_updating()
    )


@pytestrail.case('C2704', 'C2705', 'C2706')
@pytest.mark.smoke
@pytest.mark.parametrize('to', [
    True,  # set random future date
    False,  # w/o to param
    "past_date"  # set random past date
])
def test_close_tax_rate(to):
    tax_rate = TaxRates()
    assertion_steps = TaxRatesAssertionSteps(tax_rate)

    tax_rate.compose_create_payload().create()
    assertion_steps.validate_post_response_is_correct()

    tax_rate.compose_close_payload(
        to=to, date_in_past=False if to != 'past_date' else True).close()
    assertion_steps.check_close_response_is_successful()

    assertion_steps.check_object_revision_status(
        RevisionStatus.EXPIRED if to in [False, "past_date"] else RevisionStatus.ACTIVE)

    assertion_steps.validate_get_response_is_correct(
        expected_response=tax_rate.generate_expected_response_after_close())


@pytestrail.case('C2707')
@pytest.mark.smoke
def test_close_and_new_tax_rate():
    tax_rate = TaxRates()
    assertion_steps = TaxRatesAssertionSteps(tax_rate)

    get_id_from_response(tax_rate.compose_create_payload().create())  # init revision
    assertion_steps.validate_post_response_is_correct()

    tax_rate.get_by_id()
    assertion_steps.validate_post_response_is_correct()

    new_revision_id = get_id_from_response(tax_rate.compose_close_and_new_payload().close_and_new())
    assertion_steps.check_object_has_new_to_date_after_close_and_new()

    tax_rate.get_by_id(new_revision_id)
    assertion_steps.validate_get_response_is_correct(
        expected_response=tax_rate.generate_expected_response_after_close_and_new())


@pytestrail.case('C2708')
@pytest.mark.smoke
def test_delete_tax_rate():
    tax_rate = TaxRates()
    assertion_steps = TaxRatesAssertionSteps(tax_rate)

    tax_rate.compose_create_payload().create()
    assertion_steps.check_post_response_is_successful()

    tax_rate.delete()
    assertion_steps.check_object_is_deleted_successfully()