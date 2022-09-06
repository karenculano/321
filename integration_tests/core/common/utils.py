import random
from copy import deepcopy
from datetime import date, timedelta, datetime
from json import dumps
from typing import Callable

from faker import Faker
from pytz import utc
from requests import Response

from core.common.entities import DATE_PATTERN, DATE_TIME_PATTERN
from core.common.logger import LOGGER

FAKE = Faker()


def api_logger(func: Callable):
    def inner(*args, **kwargs):
        response = func(*args, **kwargs)
        LOGGER.info(f"REQUEST: {response.request.method}: {response.request.url}")
        if response.request.method in ["POST", "PUT", "PATCH"]:
            LOGGER.info(f"REQUEST_DATA: {response.request.body}")
        LOGGER.info(f'CONTENT: {response.status_code}":" {response.content}')

        return response

    return inner


def get_random_str(n: int = 10) -> str:
    return FAKE.bothify(text="?" * n)


def get_random_int(start: int = 100000000000000, stop: int = 9999999999999999) -> int:
    return FAKE.random_int(min=start, max=stop)


def dumps_values(init_dict: dict) -> dict:
    if isinstance(init_dict, dict):
        result_dict = deepcopy(init_dict)
        for k, v in result_dict.items():
            if isinstance(v, (list, dict)):
                result_dict[k] = dumps(v)
        return result_dict


def get_id_from_response(response: Response) -> str:
    return response.json().get('entity').get("_id").get('$id')


def get_details(response: Response) -> list:
    """
    :param response: GET
    """
    return response.json().get('details')


def get_entity(response: Response) -> dict:
    """
    :rtype: object
    :param response: POST
    """
    return response.json().get('entity')


def get_random_past_or_future_date(
        range_nearest_days: int = 10, past: bool = True, start_range_from: int = None
) -> date:
    if past:
        return (date.today() - timedelta(days=start_range_from or 0)) - timedelta(
            days=random.randint(1, range_nearest_days))
    else:
        return (date.today() + timedelta(days=start_range_from or 0)) + timedelta(
            days=random.randint(1, range_nearest_days))


def convert_date_to_str(date_: date, pattern: str = DATE_PATTERN) -> str:
    return date_.strftime(pattern)


def convert_date_str_to_datetime_str(date_str: str) -> str:
    date_time = datetime.strptime(date_str, DATE_PATTERN)
    # we cut 2 digits from the end due to specific datetime format in API
    return date_time.strftime(DATE_TIME_PATTERN)[:-2]


def convert_datetime_str_to_timestamp(date_str: str, pattern: str = DATE_PATTERN) -> float:
    return datetime.strptime(date_str, pattern).replace(tzinfo=utc).timestamp()


def convert_date_to_date_obj(date_str: str, pattern: str = DATE_PATTERN) -> dict:
    """:return {"sec": 160000000, "usec": 0}"""
    if date_str:
        date_ = datetime.strptime(date_str, pattern).replace(tzinfo=utc)
        msec = date_.microsecond
        return {
            "sec": int(date_.replace(microsecond=0).timestamp()),
            "usec": msec
        }


def convert_date_fields_to_expected(expected_obj: dict, fields: list, method: str) -> None:
    """we have diff type of date fields between GET and other API methods"""
    for key, value in expected_obj.items():
        if key in fields:
            if method == 'GET':
                expected_obj[key] = convert_date_str_to_datetime_str(value)
            else:
                expected_obj[key] = convert_date_to_date_obj(value)


def to_float_date_obj_form_get(to: dict, msec: bool = True) -> float:
    """as for now 'to' param contains sec and usec values"""
    return float(f'{to.get("sec")}.{to.get("usec")}') if msec else float(to.get("sec"))


def remove_keys_for_missing_values(raw_dict: dict) -> dict:
    if isinstance(raw_dict, dict):
        for key, value in list(
                raw_dict.items()
        ):  # dictionary changed size during iteration
            if not value:
                del raw_dict[key]
            if isinstance(value, dict):
                remove_keys_for_missing_values(value)
            elif isinstance(value, list):
                for element in value:
                    remove_keys_for_missing_values(element)
                    if isinstance(element, dict) and not value[0]:  # for case [{}]
                        del raw_dict[key]
    return raw_dict


def remove_keys_if_value_is_none(raw_dict: dict) -> dict:
    if isinstance(raw_dict, dict):
        for key in list(raw_dict.keys()):
            if raw_dict[key] is None:
                del raw_dict[key]

    return raw_dict


def find_item_by_id(response: Response, id_: str) -> dict:
    """
    response: GET
    """
    for item in get_details(response):
        if id_ == item['_id']['$id']:
            return item


def get_true_or_false() -> bool:
    return random.choice([True, False])