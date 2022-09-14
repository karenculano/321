import pytest
from pytest_testrail.plugin import pytestrail

from core.common.entities import RevisionStatus
from core.common.utils import get_id_from_response
from steps.backend_steps.subscribers_steps import Subscribers, SubscribersAssertionSteps


@pytestrail.case('C2674')
@pytest.mark.smoke
def test_create_subscriber():
    subscriber = Subscribers()
    assertion_steps = SubscribersAssertionSteps(subscriber)

    subscriber.compose_create_payload().create()
    assertion_steps.validate_post_response_is_correct()

    subscriber.get_by_id()
    assertion_steps.validate_get_response_is_correct()


@pytestrail.case('C2675')
@pytest.mark.smoke
def test_update_subscriber():
    subscriber = Subscribers()
    assertion_steps = SubscribersAssertionSteps(subscriber)

    subscriber.compose_create_payload().create()
    assertion_steps.validate_post_response_is_correct()

    subscriber.compose_update_payload().update()
    assertion_steps.check_update_response_is_successful()

    subscriber.get_by_id()
    assertion_steps.validate_get_response_is_correct(
        expected_response=subscriber.generate_expected_response_after_updating())


@pytestrail.case('C2676', 'C2691', 'C2692')
@pytest.mark.parametrize('to', [
    True,  # set random future date
    False,  # w/o to param
    "past_date"  # set random past date
])
@pytest.mark.smoke
def test_close_subscriber(to):
    subscriber = Subscribers()
    assertion_steps = SubscribersAssertionSteps(subscriber)

    subscriber.compose_create_payload().create()
    assertion_steps.check_post_response_is_successful()

    subscriber.compose_close_payload(
        to=to, date_in_past=False if to != 'past_date' else True).close()
    assertion_steps.check_close_response_is_successful()

    assertion_steps.check_object_revision_status(
        status=RevisionStatus.EXPIRED if to in [False, "past_date"] else RevisionStatus.ACTIVE)

    assertion_steps.validate_get_response_is_correct(
        expected_response=subscriber.generate_expected_response_after_close())


@pytestrail.case('C2677')
@pytest.mark.smoke
def test_close_and_new_subscriber():
    subscriber = Subscribers()
    assertion_steps = SubscribersAssertionSteps(subscriber)

    get_id_from_response(subscriber.compose_create_payload().create())  # init revision
    assertion_steps.validate_post_response_is_correct()

    subscriber.get_by_id()
    assertion_steps.validate_get_response_is_correct()

    new_revision_id = get_id_from_response(
        subscriber.compose_close_and_new_payload().close_and_new())
    assertion_steps.check_object_has_new_to_date_after_close_and_new()

    subscriber.get_by_id(id_=new_revision_id)
    assertion_steps.validate_get_response_is_correct(
        expected_response=subscriber.generate_expected_response_after_close_and_new())


@pytestrail.case('C2678')
@pytest.mark.smoke
def test_delete_subscriber():
    subscriber = Subscribers()
    assertion_steps = SubscribersAssertionSteps(subscriber)

    subscriber.compose_create_payload().create()
    assertion_steps.check_post_response_is_successful()

    subscriber.delete()
    assertion_steps.check_object_is_deleted_successfully()


@pytestrail.case('C2711')
@pytest.mark.smoke
def test_permanentchange_subscriber():
    subscriber = Subscribers()
    assertion_steps = SubscribersAssertionSteps(subscriber)

    subscriber.compose_create_payload().create()
    assertion_steps.validate_post_response_is_correct()

    subscriber.compose_permanent_change_payload().do_permanent_change()
    assertion_steps.check_permanent_change_is_successful(
        expected_objects=subscriber.generate_expected_objects_after_permanent_change())